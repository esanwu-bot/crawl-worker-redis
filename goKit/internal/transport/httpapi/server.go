// Package httpapi 提供采集引擎的 REST/Admin/Agent API。
//
// 这是「采集 Agent 工作台 / Orchestrator」的后端入口：
// Workbench 与 Agent 均通过本 API 创建作业、观察进度、处理死信。
package httpapi

import (
	"context"
	"encoding/json"
	"net/http"
	"strconv"
	"time"

	"crawlkit/internal/crawler"
	"crawlkit/internal/domain/job"
	"crawlkit/internal/infrastructure/mysqlstore"
	"crawlkit/internal/infrastructure/redisstore"
)

// Server HTTP API 服务器。
type Server struct {
	engine *crawler.Engine
	start  time.Time
}

// New 创建服务器。
func New(engine *crawler.Engine) *Server {
	return &Server{engine: engine, start: time.Now()}
}

// Handler 构建路由。
func (s *Server) Handler() http.Handler {
	mux := http.NewServeMux()

	mux.HandleFunc("GET /api/v1/health", s.handleHealth)
	mux.HandleFunc("GET /api/v1/sources", s.handleSources)
	mux.HandleFunc("GET /api/v1/metrics", s.handleMetrics)
	mux.HandleFunc("GET /api/v1/stats", s.handleMetrics)

	mux.HandleFunc("GET /api/v1/jobs", s.handleListJobs)
	mux.HandleFunc("POST /api/v1/jobs", s.handleCreateJob)
	mux.HandleFunc("GET /api/v1/jobs/{id}", s.handleGetJob)
	mux.HandleFunc("POST /api/v1/jobs/{id}/cancel", s.handleJobCancel)
	mux.HandleFunc("POST /api/v1/jobs/{id}/pause", s.handleJobPause)
	mux.HandleFunc("POST /api/v1/jobs/{id}/resume", s.handleJobResume)

	mux.HandleFunc("GET /api/v1/dead", s.handleListDead)
	mux.HandleFunc("POST /api/v1/dead/requeue", s.handleRequeueDead)

	mux.HandleFunc("GET /api/v1/results", s.handleResults)

	return withRecovery(s.engine, mux)
}

// ---------- 基础 ----------

func (s *Server) handleHealth(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	status := "ok"
	if err := s.engine.Store.Client().Ping(ctx).Err(); err != nil {
		status = "degraded"
	}
	writeJSON(w, http.StatusOK, map[string]any{
		"status":   status,
		"uptime_s": int(time.Since(s.start).Seconds()),
		"redis":    errText(s.engine.Store.Client().Ping(ctx).Err()),
		"database": errText(s.engine.DB.DB().PingContext(ctx)),
		"time":     time.Now().Format(time.RFC3339),
	})
}

func (s *Server) handleSources(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	probe := r.URL.Query().Get("probe") == "1"
	out := make([]map[string]any, 0)
	for _, name := range s.engine.Adapters.Sources() {
		sc := s.engine.Cfg.Sources[name]
		entry := map[string]any{
			"source":   name,
			"adapter":  sc.Adapter,
			"entity":   sc.Entity,
			"site":     sc.Site,
			"api_base": sc.APIBase,
		}
		if probe {
			a, _ := s.engine.Adapters.Get(name)
			probeCtx, cancel := context.WithTimeout(ctx, 5*time.Second)
			entry["healthy"] = errText(a.HealthCheck(probeCtx)) == ""
			cancel()
		}
		out = append(out, entry)
	}
	types, _ := s.engine.DB.ListSourceTypes(ctx, "")
	writeJSON(w, http.StatusOK, map[string]any{"sources": out, "units": types})
}

func (s *Server) handleMetrics(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	snap := s.engine.Store.Snapshot(ctx)
	byEntity, _ := s.engine.DB.RecordsByEntity(ctx)
	payload := map[string]any{
		"stream_len":        snap.StreamLen,
		"pending":           snap.Pending,
		"dead_letters":      snap.DeadLetters,
		"counters":          snap.Counters,
		"records_by_entity": byEntity,
		"proxy":             s.proxyStats(ctx),
	}
	writeJSON(w, http.StatusOK, payload)
}

func (s *Server) proxyStats(ctx context.Context) any {
	if s.engine.Pool == nil {
		return map[string]any{"enabled": false, "size": 0}
	}
	return map[string]any{"enabled": true, "size": s.engine.Pool.Size(), "proxies": s.engine.Pool.Stats(ctx)}
}

// ---------- 作业 ----------

func (s *Server) handleCreateJob(w http.ResponseWriter, r *http.Request) {
	var req struct {
		Source   string   `json:"source"`
		Units    []string `json:"units"`
		MaxPages int      `json:"max_pages"`
		Force    bool     `json:"force"`
	}
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		writeErr(w, http.StatusBadRequest, "请求体解析失败: "+err.Error())
		return
	}
	res, err := s.engine.Producer().Seed(r.Context(), req.Source, req.Units, req.Force, req.MaxPages)
	if err != nil {
		writeErr(w, http.StatusBadRequest, err.Error())
		return
	}
	writeJSON(w, http.StatusCreated, res)
}

func (s *Server) handleListJobs(w http.ResponseWriter, r *http.Request) {
	source := r.URL.Query().Get("source")
	limit := queryInt(r, "limit", 20)
	jobs, err := s.engine.DB.ListJobs(r.Context(), source, limit)
	if err != nil {
		writeErr(w, http.StatusInternalServerError, err.Error())
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"jobs": jobs})
}

func (s *Server) handleGetJob(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")
	j, err := s.engine.DB.GetJob(r.Context(), id)
	if err != nil {
		writeErr(w, http.StatusInternalServerError, err.Error())
		return
	}
	if j == nil {
		writeErr(w, http.StatusNotFound, "作业不存在: "+id)
		return
	}
	parked := s.engine.Store.ParkedCount(r.Context(), id)
	state := s.engine.Store.GetJobState(r.Context(), id)
	writeJSON(w, http.StatusOK, map[string]any{"job": j, "runtime_state": state, "parked_tasks": parked})
}

func (s *Server) handleJobCancel(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")
	ctx := r.Context()
	if err := s.engine.Store.SetJobState(ctx, id, redisstore.StateCancelled); err != nil {
		writeErr(w, http.StatusInternalServerError, err.Error())
		return
	}
	if err := s.engine.DB.SetJobStatus(ctx, id, job.StatusCancelled); err != nil {
		s.engine.Log.Warn("回写作业状态失败: %v", err)
	}
	writeJSON(w, http.StatusOK, map[string]any{"job_id": id, "state": redisstore.StateCancelled})
}

func (s *Server) handleJobPause(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")
	ctx := r.Context()
	if err := s.engine.Store.SetJobState(ctx, id, redisstore.StatePaused); err != nil {
		writeErr(w, http.StatusInternalServerError, err.Error())
		return
	}
	if err := s.engine.DB.SetJobStatus(ctx, id, job.StatusPaused); err != nil {
		s.engine.Log.Warn("回写作业状态失败: %v", err)
	}
	writeJSON(w, http.StatusOK, map[string]any{"job_id": id, "state": redisstore.StatePaused})
}

func (s *Server) handleJobResume(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")
	ctx := r.Context()
	moved, err := s.engine.Store.ResumeJob(ctx, id)
	if err != nil {
		writeErr(w, http.StatusInternalServerError, err.Error())
		return
	}
	if err := s.engine.DB.SetJobStatus(ctx, id, job.StatusActive); err != nil {
		s.engine.Log.Warn("回写作业状态失败: %v", err)
	}
	writeJSON(w, http.StatusOK, map[string]any{"job_id": id, "state": redisstore.StateRunning, "requeued": moved})
}

// ---------- 死信 ----------

func (s *Server) handleListDead(w http.ResponseWriter, r *http.Request) {
	limit := queryInt(r, "limit", 20)
	items, err := s.engine.Store.ReadDead(r.Context(), limit)
	if err != nil {
		writeErr(w, http.StatusInternalServerError, err.Error())
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"dead_letters": items})
}

func (s *Server) handleRequeueDead(w http.ResponseWriter, r *http.Request) {
	var req struct {
		IDs   []string `json:"ids"`
		Limit int      `json:"limit"`
	}
	_ = json.NewDecoder(r.Body).Decode(&req)
	moved, err := s.engine.Store.RequeueDead(r.Context(), req.IDs, req.Limit)
	if err != nil {
		writeErr(w, http.StatusInternalServerError, err.Error())
		return
	}
	s.engine.Store.StatIncr(r.Context(), "dead_requeued", int64(moved))
	writeJSON(w, http.StatusOK, map[string]any{"requeued": moved})
}

// ---------- 结果 ----------

func (s *Server) handleResults(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	limit := queryInt(r, "limit", 20)
	rows, err := s.engine.DB.SearchRecords(r.Context(),
		q.Get("source"), q.Get("entity"), q.Get("unit_id"), mysqlstore.NormalizeKeyword(q.Get("keyword")), limit)
	if err != nil {
		writeErr(w, http.StatusInternalServerError, err.Error())
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"records": rows})
}

// ---------- 辅助 ----------

func queryInt(r *http.Request, key string, def int) int {
	if v := r.URL.Query().Get(key); v != "" {
		if n, err := strconv.Atoi(v); err == nil {
			return n
		}
	}
	return def
}

func writeJSON(w http.ResponseWriter, status int, payload any) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(payload)
}

func writeErr(w http.ResponseWriter, status int, msg string) {
	writeJSON(w, status, map[string]any{"error": msg})
}

func errText(err error) string {
	if err == nil {
		return ""
	}
	return err.Error()
}

func withRecovery(engine *crawler.Engine, next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		defer func() {
			if rec := recover(); rec != nil {
				if engine != nil && engine.Log != nil {
					engine.Log.Error("API panic: %v", rec)
				}
				writeErr(w, http.StatusInternalServerError, "internal error")
			}
		}()
		next.ServeHTTP(w, r)
	})
}

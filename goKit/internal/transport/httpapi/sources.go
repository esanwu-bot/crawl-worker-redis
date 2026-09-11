package httpapi

import (
	"context"
	"encoding/json"
	"net/http"
	"time"

	"crawlkit/internal/adapter"
	"crawlkit/internal/domain/source"
)

// ---------- Sources: 列表 / 详情 ----------

// handleSources 返回配置式数据源目录（含运行时注册与健康状态）。
//
// 保持与旧版契约兼容：sources[].source/adapter/entity/site/api_base，
// 并额外附带 status/managed/registered/page_size/detail_url。
func (s *Server) handleSources(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	probe := r.URL.Query().Get("probe") == "1"

	defs, err := s.engine.DB.ListSources(ctx)
	if err != nil {
		writeErr(w, http.StatusInternalServerError, err.Error())
		return
	}
	if len(defs) == 0 {
		// DB 尚未播种时回落到 config 声明
		for name, sc := range s.engine.Cfg.Sources {
			defs = append(defs, adapter.DefinitionFromConfig(name, sc))
		}
	}

	out := make([]map[string]any, 0, len(defs))
	for _, d := range defs {
		entry := map[string]any{
			"source":     d.ID,
			"id":         d.ID,
			"name":       d.Name,
			"adapter":    d.Adapter,
			"entity":     d.Entity,
			"site":       d.Site,
			"api_base":   d.APIBase,
			"page_size":  d.PageSize,
			"detail_url": d.DetailURL,
			"status":     d.Status,
			"managed":    true,
			"registered": s.engine.Adapters.Has(d.ID),
			"units":      len(d.Units),
		}
		if probe && d.Enabled() {
			entry["healthy"] = errText(s.probeSource(ctx, d)) == ""
		}
		out = append(out, entry)
	}

	types, _ := s.engine.DB.ListSourceTypes(ctx, "")
	entities, _ := s.engine.DB.ListEntities(ctx, "")
	writeJSON(w, http.StatusOK, map[string]any{"sources": out, "units": types, "entities": entities})
}

// handleGetSource 读取单个数据源定义。
func (s *Server) handleGetSource(w http.ResponseWriter, r *http.Request) {
	d, err := s.engine.DB.GetSource(r.Context(), r.PathValue("id"))
	if err != nil {
		writeErr(w, http.StatusInternalServerError, err.Error())
		return
	}
	if d == nil {
		writeErr(w, http.StatusNotFound, "数据源不存在: "+r.PathValue("id"))
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"source": d})
}

// handleCreateSource 创建配置式数据源。
func (s *Server) handleCreateSource(w http.ResponseWriter, r *http.Request) {
	var d source.Definition
	if err := json.NewDecoder(r.Body).Decode(&d); err != nil {
		writeErr(w, http.StatusBadRequest, "请求体解析失败: "+err.Error())
		return
	}
	d.Normalize()
	if err := d.Validate(); err != nil {
		writeErr(w, http.StatusBadRequest, err.Error())
		return
	}
	if err := s.saveSource(r.Context(), d); err != nil {
		writeErr(w, http.StatusInternalServerError, err.Error())
		return
	}
	writeJSON(w, http.StatusCreated, map[string]any{"source": d})
}

// handleUpdateSource 更新数据源定义（以路径 id 为准）。
func (s *Server) handleUpdateSource(w http.ResponseWriter, r *http.Request) {
	var d source.Definition
	if err := json.NewDecoder(r.Body).Decode(&d); err != nil {
		writeErr(w, http.StatusBadRequest, "请求体解析失败: "+err.Error())
		return
	}
	d.ID = r.PathValue("id")
	d.Normalize()
	if err := d.Validate(); err != nil {
		writeErr(w, http.StatusBadRequest, err.Error())
		return
	}
	if err := s.saveSource(r.Context(), d); err != nil {
		writeErr(w, http.StatusInternalServerError, err.Error())
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"source": d})
}

// handleDeleteSource 删除数据源。
func (s *Server) handleDeleteSource(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")
	if err := s.engine.DB.DeleteSource(r.Context(), id); err != nil {
		writeErr(w, http.StatusInternalServerError, err.Error())
		return
	}
	_ = s.engine.ReloadSources(r.Context())
	writeJSON(w, http.StatusOK, map[string]any{"deleted": id})
}

// ---------- Sources: 动作 ----------

func (s *Server) handleTestSource(w http.ResponseWriter, r *http.Request) {
	ctx, cancel := context.WithTimeout(r.Context(), 10*time.Second)
	defer cancel()
	d, err := s.engine.DB.GetSource(ctx, r.PathValue("id"))
	if err != nil {
		writeErr(w, http.StatusInternalServerError, err.Error())
		return
	}
	if d == nil {
		writeErr(w, http.StatusNotFound, "数据源不存在: "+r.PathValue("id"))
		return
	}
	if err := s.probeSource(ctx, *d); err != nil {
		writeJSON(w, http.StatusOK, map[string]any{"healthy": false, "error": err.Error()})
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"healthy": true})
}

func (s *Server) handleDiscoverSource(w http.ResponseWriter, r *http.Request) {
	ctx, cancel := context.WithTimeout(r.Context(), 30*time.Second)
	defer cancel()
	d, err := s.engine.DB.GetSource(ctx, r.PathValue("id"))
	if err != nil {
		writeErr(w, http.StatusInternalServerError, err.Error())
		return
	}
	if d == nil {
		writeErr(w, http.StatusNotFound, "数据源不存在: "+r.PathValue("id"))
		return
	}
	a, err := s.adapterFor(*d)
	if err != nil {
		writeErr(w, http.StatusBadRequest, err.Error())
		return
	}
	units, err := a.Discover(ctx, s.engine.DB)
	if err != nil {
		writeErr(w, http.StatusBadGateway, "发现采集单元失败: "+err.Error())
		return
	}
	// 同步实体目录
	for _, u := range units {
		entity := u.Entity
		if entity == "" {
			entity = d.Entity
		}
		_ = s.engine.DB.UpsertEntity(ctx, d.ID, entity, "")
	}
	// 发现结果并入数据源定义，供后续播种复用
	if d.Units == nil || len(d.Units) == 0 {
		d.Units = make([]source.UnitDefinition, 0, len(units))
		for _, u := range units {
			d.Units = append(d.Units, source.UnitDefinition{UnitID: u.UnitID, UnitName: u.UnitName, Params: u.Params})
		}
		_ = s.saveSource(ctx, *d)
	}
	writeJSON(w, http.StatusOK, map[string]any{"units": units, "count": len(units)})
}

func (s *Server) handleEnableSource(w http.ResponseWriter, r *http.Request) {
	s.setSourceStatus(w, r, source.StatusEnabled)
}

func (s *Server) handleDisableSource(w http.ResponseWriter, r *http.Request) {
	s.setSourceStatus(w, r, source.StatusDisabled)
}

func (s *Server) setSourceStatus(w http.ResponseWriter, r *http.Request, status string) {
	id := r.PathValue("id")
	if err := s.engine.DB.SetSourceStatus(r.Context(), id, status); err != nil {
		writeErr(w, http.StatusInternalServerError, err.Error())
		return
	}
	if err := s.engine.ReloadSources(r.Context()); err != nil {
		writeErr(w, http.StatusInternalServerError, err.Error())
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"source": id, "status": status})
}

// ---------- 辅助 ----------

// saveSource 写入数据源并立即重载运行时适配器。
func (s *Server) saveSource(ctx context.Context, d source.Definition) error {
	if err := s.engine.DB.UpsertSource(ctx, d); err != nil {
		return err
	}
	if d.Entity != "" {
		_ = s.engine.DB.UpsertEntity(ctx, d.ID, d.Entity, d.Name)
	}
	return s.engine.ReloadSources(ctx)
}

// adapterFor 优先取运行中适配器，否则按定义即时构造（用于尚未启用的数据源测试）。
func (s *Server) adapterFor(d source.Definition) (source.Adapter, error) {
	if a, err := s.engine.Adapters.Get(d.ID); err == nil {
		return a, nil
	}
	return adapter.BuildFromDefinition(d, s.engine.Client)
}

func (s *Server) probeSource(ctx context.Context, d source.Definition) error {
	a, err := s.adapterFor(d)
	if err != nil {
		return err
	}
	return a.HealthCheck(ctx)
}

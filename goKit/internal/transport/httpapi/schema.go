package httpapi

import (
	"context"
	"encoding/json"
	"net/http"
	"strconv"
	"time"

	"crawlkit/internal/domain/source"
	"crawlkit/internal/domain/task"
)

// ---------- Schema: CRUD ----------

// handleListSchemas 列出数据源某实体（或全部）的 Schema 版本。
func (s *Server) handleListSchemas(w http.ResponseWriter, r *http.Request) {
	schs, err := s.engine.DB.ListSchemas(r.Context(), r.PathValue("id"), r.URL.Query().Get("entity"))
	if err != nil {
		writeErr(w, http.StatusInternalServerError, err.Error())
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"schemas": schs})
}

// handleCreateSchema 为数据源新建一个 Schema 版本。
func (s *Server) handleCreateSchema(w http.ResponseWriter, r *http.Request) {
	var sch source.Schema
	if err := json.NewDecoder(r.Body).Decode(&sch); err != nil {
		writeErr(w, http.StatusBadRequest, "请求体解析失败: "+err.Error())
		return
	}
	sch.ID = 0
	sch.Source = r.PathValue("id")
	sch.Version = 0
	saved, err := s.engine.DB.SaveSchema(r.Context(), sch)
	if err != nil {
		writeErr(w, http.StatusBadRequest, err.Error())
		return
	}
	writeJSON(w, http.StatusCreated, map[string]any{"schema": saved})
}

// handleGetSchema 读取 Schema 详情。
func (s *Server) handleGetSchema(w http.ResponseWriter, r *http.Request) {
	id, ok := schemaID(w, r)
	if !ok {
		return
	}
	sch, err := s.engine.DB.GetSchemaByID(r.Context(), id)
	if err != nil {
		writeErr(w, http.StatusInternalServerError, err.Error())
		return
	}
	if sch == nil {
		writeErr(w, http.StatusNotFound, "schema 不存在")
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"schema": sch})
}

// handleUpdateSchema 更新指定 Schema 版本的字段映射。
func (s *Server) handleUpdateSchema(w http.ResponseWriter, r *http.Request) {
	id, ok := schemaID(w, r)
	if !ok {
		return
	}
	var sch source.Schema
	if err := json.NewDecoder(r.Body).Decode(&sch); err != nil {
		writeErr(w, http.StatusBadRequest, "请求体解析失败: "+err.Error())
		return
	}
	sch.ID = id
	saved, err := s.engine.DB.SaveSchema(r.Context(), sch)
	if err != nil {
		writeErr(w, http.StatusBadRequest, err.Error())
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"schema": saved})
}

// handleDeleteSchema 删除 Schema 版本。
func (s *Server) handleDeleteSchema(w http.ResponseWriter, r *http.Request) {
	id, ok := schemaID(w, r)
	if !ok {
		return
	}
	if err := s.engine.DB.DeleteSchema(r.Context(), id); err != nil {
		writeErr(w, http.StatusInternalServerError, err.Error())
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"deleted": id})
}

// ---------- Schema: 动作 ----------

// handleTestSchema 用样例数据验证字段映射；未提供 sample 时尝试抓取一条真实记录。
func (s *Server) handleTestSchema(w http.ResponseWriter, r *http.Request) {
	id, ok := schemaID(w, r)
	if !ok {
		return
	}
	ctx := r.Context()
	sch, err := s.engine.DB.GetSchemaByID(ctx, id)
	if err != nil {
		writeErr(w, http.StatusInternalServerError, err.Error())
		return
	}
	if sch == nil {
		writeErr(w, http.StatusNotFound, "schema 不存在")
		return
	}

	var body struct {
		Sample map[string]any `json:"sample"`
	}
	_ = json.NewDecoder(r.Body).Decode(&body)

	sample := body.Sample
	fetched := false
	if sample == nil {
		if sample = s.fetchSample(ctx, *sch); sample != nil {
			fetched = true
		}
	}
	if sample == nil {
		writeErr(w, http.StatusBadRequest, "未提供 sample，且无法从数据源抓取样例")
		return
	}

	mapped, missing := sch.Apply(sample)
	writeJSON(w, http.StatusOK, map[string]any{
		"mapped":  mapped,
		"missing": missing,
		"fetched": fetched,
		"raw":     sample,
	})
}

// handlePublishSchema 发布 Schema 版本。
func (s *Server) handlePublishSchema(w http.ResponseWriter, r *http.Request) {
	id, ok := schemaID(w, r)
	if !ok {
		return
	}
	if err := s.engine.DB.PublishSchema(r.Context(), id); err != nil {
		writeErr(w, http.StatusBadRequest, err.Error())
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"published": id})
}

// ---------- 辅助 ----------

// fetchSample 用数据源适配器抓取一条原始记录作为映射样例。
func (s *Server) fetchSample(ctx context.Context, sch source.Schema) map[string]any {
	def, err := s.engine.DB.GetSource(ctx, sch.Source)
	if err != nil || def == nil {
		return nil
	}
	a, err := s.adapterFor(*def)
	if err != nil {
		return nil
	}
	unitID := ""
	if len(def.Units) > 0 {
		unitID = def.Units[0].UnitID
	}
	if unitID == "" {
		return nil
	}
	runCtx, cancel := context.WithTimeout(ctx, 15*time.Second)
	defer cancel()
	res, err := a.ExecuteList(runCtx, &task.Payload{
		Source:    sch.Source,
		Entity:    sch.Entity,
		Operation: task.OpList,
		Cursor:    task.Cursor{UnitID: unitID, Page: 1},
	})
	if err != nil || res == nil {
		return nil
	}
	for _, rec := range res.Records {
		if rec.Raw != nil {
			return rec.Raw
		}
	}
	return nil
}

func schemaID(w http.ResponseWriter, r *http.Request) (int64, bool) {
	id, err := strconv.ParseInt(r.PathValue("id"), 10, 64)
	if err != nil || id <= 0 {
		writeErr(w, http.StatusBadRequest, "非法 schema id: "+r.PathValue("id"))
		return 0, false
	}
	return id, true
}

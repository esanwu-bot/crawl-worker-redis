package source

import (
	"fmt"
	"sort"
	"strconv"
	"strings"
	"time"
)

// Schema 版本状态。
const (
	SchemaStatusDraft     = "draft"
	SchemaStatusPublished = "published"
)

// 字段类型（Canonical 落库类型提示）。
var fieldTypes = map[string]bool{
	"string":  true,
	"integer": true,
	"number":  true,
	"boolean": true,
	"text":    true,
	"json":    true,
}

// SchemaField 是外部字段 → Canonical 字段的一条映射。
type SchemaField struct {
	Field      string `json:"field"`
	SourcePath string `json:"source_path"`
	Type       string `json:"type"`
	Required   bool   `json:"required"`
	Remark     string `json:"remark"`
	Sort       int    `json:"sort"`
}

// Pagination 定位列表接口的分页字段（JSON 路径）。
type Pagination struct {
	Items     string `json:"items"`
	Page      string `json:"page"`
	PageCount string `json:"page_count"`
	Total     string `json:"total"`
}

// Schema 是实体级字段映射定义（source_schemas 一行 + source_fields）。
type Schema struct {
	ID         int64         `json:"id"`
	Source     string        `json:"source"`
	Entity     string        `json:"entity"`
	Version    int           `json:"version"`
	Status     string        `json:"status"`
	Fields     []SchemaField `json:"fields"`
	Pagination Pagination    `json:"pagination"`
	CreatedAt  time.Time     `json:"created_at"`
	UpdatedAt  time.Time     `json:"updated_at"`
}

// Normalize 补齐默认值。
func (s *Schema) Normalize() {
	s.Source = strings.TrimSpace(s.Source)
	s.Entity = strings.TrimSpace(s.Entity)
	if s.Status == "" {
		s.Status = SchemaStatusDraft
	}
	if s.Version <= 0 {
		s.Version = 1
	}
	for i := range s.Fields {
		s.Fields[i].Field = strings.TrimSpace(s.Fields[i].Field)
		s.Fields[i].SourcePath = strings.TrimSpace(s.Fields[i].SourcePath)
		if s.Fields[i].Type == "" {
			s.Fields[i].Type = "string"
		}
		if s.Fields[i].Sort == 0 {
			s.Fields[i].Sort = i
		}
	}
}

// Validate 校验 Schema 合法性。
func (s *Schema) Validate() error {
	if s.Source == "" || s.Entity == "" {
		return fmt.Errorf("schema 缺少 source/entity")
	}
	if len(s.Fields) == 0 {
		return fmt.Errorf("schema 至少需要一个字段")
	}
	seen := map[string]bool{}
	for _, f := range s.Fields {
		if f.Field == "" {
			return fmt.Errorf("存在空字段名")
		}
		if seen[f.Field] {
			return fmt.Errorf("字段重复: %s", f.Field)
		}
		seen[f.Field] = true
		if f.SourcePath == "" {
			return fmt.Errorf("字段 %s 缺少 source_path", f.Field)
		}
		if !fieldTypes[f.Type] {
			return fmt.Errorf("字段 %s 类型非法: %s", f.Field, f.Type)
		}
	}
	return nil
}

// Apply 按字段映射从一条原始记录中抽取 Canonical payload。
//
// 返回映射结果与缺失的必填字段列表；原始键名不做存在性强制（缺失即跳过）。
func (s *Schema) Apply(sample map[string]any) (map[string]any, []string) {
	out := make(map[string]any, len(s.Fields))
	var missing []string
	for _, f := range s.Fields {
		v, ok := ExtractPath(sample, f.SourcePath)
		if !ok || v == nil {
			if f.Required {
				missing = append(missing, f.Field)
			}
			continue
		}
		out[f.Field] = coerceValue(v, f.Type)
	}
	return out, missing
}

// ExtractPath 按轻量 JSONPath 从任意值中取值。
//
// 支持 `$.a.b`、`a.b`、`$.list[0].name`、`$[0].id`；不依赖第三方库。
func ExtractPath(root any, path string) (any, bool) {
	p := strings.TrimSpace(path)
	if p == "" || p == "$" {
		return root, true
	}
	p = strings.TrimPrefix(p, "$")
	p = strings.TrimPrefix(p, ".")
	cur := root
	for len(p) > 0 {
		i := 0
		for i < len(p) && p[i] != '.' && p[i] != '[' {
			i++
		}
		if key := p[:i]; key != "" {
			m, ok := asMap(cur)
			if !ok {
				return nil, false
			}
			v, ok := m[key]
			if !ok {
				return nil, false
			}
			cur = v
		}
		p = p[i:]
		for len(p) > 0 && p[0] == '[' {
			j := strings.IndexByte(p, ']')
			if j < 0 {
				return nil, false
			}
			arr, ok := asSlice(cur)
			if !ok {
				return nil, false
			}
			n, err := strconv.Atoi(strings.TrimSpace(p[1:j]))
			if err != nil || n < 0 || n >= len(arr) {
				return nil, false
			}
			cur = arr[n]
			p = p[j+1:]
		}
		if len(p) > 0 && p[0] == '.' {
			p = p[1:]
		}
	}
	return cur, true
}

// ApplySchemaFields 按字段列表抽取（供 handler 直接使用）。
func ApplySchemaFields(fields []SchemaField, sample map[string]any) (map[string]any, []string) {
	s := &Schema{Fields: fields}
	s.Normalize()
	return s.Apply(sample)
}

// SortFields 按 Sort 稳定排序字段。
func SortFields(fields []SchemaField) {
	sort.SliceStable(fields, func(i, j int) bool { return fields[i].Sort < fields[j].Sort })
}

func asMap(v any) (map[string]any, bool) {
	m, ok := v.(map[string]any)
	return m, ok
}

func asSlice(v any) ([]any, bool) {
	a, ok := v.([]any)
	return a, ok
}

func coerceValue(v any, typ string) any {
	switch typ {
	case "integer":
		return toInt64(v)
	case "number":
		return toFloat(v)
	case "boolean":
		return toBool(v)
	case "text", "string":
		return toString(v)
	case "json":
		return v
	default:
		return v
	}
}

func toString(v any) string {
	switch x := v.(type) {
	case nil:
		return ""
	case string:
		return x
	case float64:
		if x == float64(int64(x)) {
			return strconv.FormatInt(int64(x), 10)
		}
		return strconv.FormatFloat(x, 'f', -1, 64)
	default:
		return fmt.Sprint(v)
	}
}

func toInt64(v any) int64 {
	switch x := v.(type) {
	case float64:
		return int64(x)
	case int:
		return int64(x)
	case int64:
		return x
	case string:
		n, _ := strconv.ParseInt(strings.TrimSpace(x), 10, 64)
		return n
	default:
		n, _ := strconv.ParseInt(fmt.Sprint(v), 10, 64)
		return n
	}
}

func toFloat(v any) float64 {
	switch x := v.(type) {
	case float64:
		return x
	case int:
		return float64(x)
	case int64:
		return float64(x)
	case string:
		f, _ := strconv.ParseFloat(strings.TrimSpace(x), 64)
		return f
	default:
		f, _ := strconv.ParseFloat(fmt.Sprint(v), 64)
		return f
	}
}

func toBool(v any) bool {
	switch x := v.(type) {
	case bool:
		return x
	case string:
		s := strings.ToLower(strings.TrimSpace(x))
		return s == "1" || s == "true" || s == "yes" || s == "on"
	case float64:
		return x != 0
	default:
		return false
	}
}

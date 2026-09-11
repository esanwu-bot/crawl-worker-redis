package source

import (
	"reflect"
	"testing"
)

func TestExtractPath(t *testing.T) {
	sample := map[string]any{
		"vod_id":   float64(151415),
		"vod_name": "欲望的陷阱",
		"nested":   map[string]any{"a": map[string]any{"b": "deep"}},
		"list": []any{
			map[string]any{"name": "first"},
			map[string]any{"name": "second"},
		},
	}

	cases := []struct {
		path  string
		want  any
		found bool
	}{
		{"$.vod_name", "欲望的陷阱", true},
		{"vod_id", float64(151415), true},
		{"$.nested.a.b", "deep", true},
		{"nested.a.b", "deep", true},
		{"$.list[1].name", "second", true},
		{"list[0].name", "first", true},
		{"$.missing", nil, false},
		{"$.list[9].name", nil, false},
		{"$.vod_name.tooDeep", nil, false},
		{"$", sample, true},
	}
	for _, c := range cases {
		got, ok := ExtractPath(sample, c.path)
		if ok != c.found {
			t.Fatalf("ExtractPath(%q) found=%v want %v", c.path, ok, c.found)
		}
		if c.found && !reflect.DeepEqual(got, c.want) {
			t.Fatalf("ExtractPath(%q) = %v want %v", c.path, got, c.want)
		}
	}
}

func TestExtractPathRootArray(t *testing.T) {
	root := []any{map[string]any{"id": "x"}}
	got, ok := ExtractPath(root, "$[0].id")
	if !ok || got != "x" {
		t.Fatalf("root array extraction failed: got=%v ok=%v", got, ok)
	}
}

func TestSchemaValidate(t *testing.T) {
	good := Schema{
		Source: "maccms", Entity: "vod",
		Fields: []SchemaField{
			{Field: "external_id", SourcePath: "$.vod_id", Type: "string", Required: true},
			{Field: "title", SourcePath: "$.vod_name", Type: "string"},
		},
	}
	if err := good.Validate(); err != nil {
		t.Fatalf("合法 schema 校验失败: %v", err)
	}

	noFields := Schema{Source: "s", Entity: "e"}
	if err := noFields.Validate(); err == nil {
		t.Fatal("空字段 schema 应校验失败")
	}

	dup := Schema{Source: "s", Entity: "e", Fields: []SchemaField{
		{Field: "a", SourcePath: "$.a", Type: "string"},
		{Field: "a", SourcePath: "$.b", Type: "string"},
	}}
	if err := dup.Validate(); err == nil {
		t.Fatal("重复字段应校验失败")
	}

	badType := Schema{Source: "s", Entity: "e", Fields: []SchemaField{
		{Field: "a", SourcePath: "$.a", Type: "uuid"},
	}}
	if err := badType.Validate(); err == nil {
		t.Fatal("非法字段类型应校验失败")
	}
}

func TestSchemaApply(t *testing.T) {
	sch := Schema{
		Source: "maccms", Entity: "vod",
		Fields: []SchemaField{
			{Field: "external_id", SourcePath: "$.vod_id", Type: "string", Required: true},
			{Field: "title", SourcePath: "$.vod_name", Type: "string", Required: true},
			{Field: "year", SourcePath: "$.vod_year", Type: "integer"},
			{Field: "score", SourcePath: "$.vod_score", Type: "number"},
			{Field: "raw_flag", SourcePath: "$.flag", Type: "boolean"},
		},
	}
	sample := map[string]any{
		"vod_id":    float64(151415),
		"vod_name":  "欲望的陷阱",
		"vod_year":  "2026",
		"vod_score": "8.5",
		"flag":      "true",
	}
	got, missing := sch.Apply(sample)
	if len(missing) != 0 {
		t.Fatalf("不应缺失必填: %v", missing)
	}
	if got["external_id"] != "151415" {
		t.Fatalf("external_id coercion 错误: %v", got["external_id"])
	}
	if got["year"] != int64(2026) {
		t.Fatalf("year coercion 错误: %v (%T)", got["year"], got["year"])
	}
	if got["score"] != 8.5 {
		t.Fatalf("score coercion 错误: %v", got["score"])
	}
	if got["raw_flag"] != true {
		t.Fatalf("boolean coercion 错误: %v", got["raw_flag"])
	}
}

func TestSchemaApplyMissingRequired(t *testing.T) {
	sch := Schema{
		Source: "maccms", Entity: "vod",
		Fields: []SchemaField{
			{Field: "external_id", SourcePath: "$.vod_id", Type: "string", Required: true},
			{Field: "title", SourcePath: "$.vod_name", Type: "string", Required: true},
		},
	}
	_, missing := sch.Apply(map[string]any{"vod_id": "1"})
	if len(missing) != 1 || missing[0] != "title" {
		t.Fatalf("缺失必填字段检测错误: %v", missing)
	}
}

func TestDefinitionNormalizeValidate(t *testing.T) {
	d := Definition{ID: "maccms", APIBase: "https://x/api", Adapter: "maccms"}
	d.Normalize()
	if d.Status != StatusEnabled {
		t.Fatalf("status 默认值应为 enabled: %s", d.Status)
	}
	if d.Name != "maccms" {
		t.Fatalf("name 默认值应为 id: %s", d.Name)
	}
	if err := d.Validate(); err != nil {
		t.Fatalf("合法定义校验失败: %v", err)
	}

	bad := Definition{ID: ""}
	if err := bad.Validate(); err == nil {
		t.Fatal("缺 id 应校验失败")
	}
}

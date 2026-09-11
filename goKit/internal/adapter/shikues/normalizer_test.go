package shikues

import "testing"

func TestLooksLikePackage(t *testing.T) {
	cases := map[string]bool{
		"SOD123": true,
		"TO-220": true,
		"SMA":    true,
		"DO":     true,
		"50":     false,
		"40V":    false,
		"1.5A":   false,
		"":       false,
		"ABC":    true,
	}
	for in, want := range cases {
		if got := looksLikePackage(in); got != want {
			t.Errorf("looksLikePackage(%q) = %v, want %v", in, got, want)
		}
	}
}

func TestToModelRows(t *testing.T) {
	items := []any{
		map[string]any{"id": 101, "a": "SS34", "b": "40V", "c": "3A", "d": "SMA", "pdf": "http://x/ss34.pdf"},
		map[string]any{"id": 102, "a": "1N4148", "b": "100V"},
		map[string]any{"id": 103}, // 无型号，应被丢弃
	}
	rows := toModelRows(items, 3, "肖特基", "http://api/list")
	if len(rows) != 2 {
		t.Fatalf("应清洗出 2 行，实际 %d", len(rows))
	}
	if rows[0].Model != "SS34" || rows[0].RemoteID != 101 {
		t.Fatalf("首行不符: %+v", rows[0])
	}
	if rows[0].Package != "SMA" {
		t.Fatalf("封装识别错误: %q", rows[0].Package)
	}
	if rows[0].TypeID != 3 || rows[0].TypeName != "肖特基" {
		t.Fatalf("单元信息未回填: %+v", rows[0])
	}
	if got := rows[0].Specs["b"]; got != "40V" {
		t.Fatalf("specs 保留失败: %v", got)
	}
	if rows[0].PDF != "http://x/ss34.pdf" {
		t.Fatalf("pdf 字段丢失: %q", rows[0].PDF)
	}
}

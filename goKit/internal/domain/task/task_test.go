package task

import "testing"

func TestHomeAndNext(t *testing.T) {
	u := Unit{Entity: "model", UnitID: "3", UnitName: "肖特基"}
	t1 := Home(u, "shikues", 1, 5, "job-1")

	if !t1.Valid() {
		t.Fatalf("首页任务应合法: %+v", t1)
	}
	if got := t1.CursorKey(); got != "cur:shikues:model:3" {
		t.Fatalf("CursorKey 不符: %s", got)
	}
	if got := t1.TargetPagesOr(99); got != 5 {
		t.Fatalf("TargetPages 应为 5，实际 %d", got)
	}

	t2 := t1.Next()
	if t2.Cursor.Page != 2 {
		t.Fatalf("下一页应为 2，实际 %d", t2.Cursor.Page)
	}
	if t1.Cursor.Page != 1 {
		t.Fatalf("Next 不应修改原任务，实际 %d", t1.Cursor.Page)
	}
	if t2.JobID != "job-1" || t2.Source != "shikues" || t2.Cursor.UnitID != "3" {
		t.Fatalf("Next 应继承上下文: %+v", t2)
	}
}

func TestTargetPagesFallback(t *testing.T) {
	p := &Payload{Operation: OpList, Source: "s", Entity: "e", Cursor: Cursor{UnitID: "1", Page: 1}}
	if got := p.TargetPagesOr(7); got != 7 {
		t.Fatalf("未显式设置时应回退，实际 %d", got)
	}
}

func TestValidRejectsWrongOp(t *testing.T) {
	p := &Payload{Operation: "detail", Source: "s", Entity: "e", Cursor: Cursor{UnitID: "1", Page: 1}}
	if p.Valid() {
		t.Fatal("非 list 任务不应合法")
	}
}

func TestEncodeDecodeRoundTrip(t *testing.T) {
	p := Home(Unit{Entity: "vod", UnitID: "7", UnitName: "喜剧片"}, "maccms", 3, 10, "job-9")
	raw, err := p.Encode()
	if err != nil {
		t.Fatal(err)
	}
	got, err := Decode(raw)
	if err != nil {
		t.Fatal(err)
	}
	if got.Cursor.UnitID != "7" || got.Cursor.Page != 3 || got.JobID != "job-9" {
		t.Fatalf("往返不一致: %+v", got)
	}
}

func TestDecodeDoubleEncoded(t *testing.T) {
	// 兼容被二次 JSON 字符串包装的载荷
	raw := []byte(`"{\"source\":\"shikues\",\"entity\":\"model\",\"operation\":\"list\",\"cursor\":{\"unit_id\":\"1\",\"page\":1}}"`)
	got, err := Decode(raw)
	if err != nil {
		t.Fatalf("应兼容二次包装: %v", err)
	}
	if got.Source != "shikues" || got.Cursor.Page != 1 {
		t.Fatalf("解析结果不符: %+v", got)
	}
}

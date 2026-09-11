package crawler

import "time"

func durationMS(ms int) time.Duration {
	if ms <= 0 {
		return 0
	}
	return time.Duration(ms) * time.Millisecond
}

// copyParams 复制任务参数并注入 limit，避免污染上游载荷。
func copyParams(in map[string]any, limit int) map[string]any {
	out := make(map[string]any, len(in)+1)
	for k, v := range in {
		out[k] = v
	}
	if limit > 0 {
		out["limit"] = limit
	}
	return out
}

// containsStr 判断切片是否包含元素。
func containsStr(list []string, s string) bool {
	for _, v := range list {
		if v == s {
			return true
		}
	}
	return false
}

// Package coerce 提供 JSON 值到基础类型的宽松转换，供各 Adapter 解析异构响应。
package coerce

import (
	"fmt"
	"strconv"
	"strings"
)

// ToString 把任意 JSON 值转为字符串。
func ToString(v any) string {
	switch t := v.(type) {
	case nil:
		return ""
	case string:
		return t
	case float64:
		if t == float64(int64(t)) {
			return strconv.FormatInt(int64(t), 10)
		}
		return strconv.FormatFloat(t, 'f', -1, 64)
	case bool:
		return strconv.FormatBool(t)
	case int:
		return strconv.Itoa(t)
	case int64:
		return strconv.FormatInt(t, 10)
	default:
		return fmt.Sprint(t)
	}
}

// ToInt 把任意 JSON 值转为整数（失败返回 0）。
func ToInt(v any) int {
	switch t := v.(type) {
	case nil:
		return 0
	case float64:
		return int(t)
	case int:
		return t
	case int64:
		return int(t)
	case string:
		n, _ := strconv.Atoi(strings.TrimSpace(t))
		return n
	default:
		n, _ := strconv.Atoi(strings.TrimSpace(fmt.Sprint(t)))
		return n
	}
}

// ToMap 把任意 JSON 值转为 map。
func ToMap(v any) map[string]any {
	if m, ok := v.(map[string]any); ok {
		return m
	}
	return nil
}

// ToSlice 把任意 JSON 值转为切片。
func ToSlice(v any) []any {
	if s, ok := v.([]any); ok {
		return s
	}
	return nil
}

// Trim 去空白。
func Trim(v any) string { return strings.TrimSpace(ToString(v)) }

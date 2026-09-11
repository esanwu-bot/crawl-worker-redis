package mysqlstore

import (
	"crypto/rand"
	"encoding/hex"
	"time"
)

// newJobID 生成作业 id：job-YYYYMMDDHHMMSS-xxxxxx（与 PHP 版风格一致）。
func newJobID() string {
	b := make([]byte, 3)
	_, _ = rand.Read(b)
	return "job-" + time.Now().Format("20060102150405") + "-" + hex.EncodeToString(b)
}

var timeLayouts = []string{
	"2006-01-02 15:04:05",
	time.RFC3339,
	"2006-01-02T15:04:05Z",
}

func parseTime(s string) (time.Time, error) {
	for _, layout := range timeLayouts {
		if t, err := time.ParseInLocation(layout, s, time.Local); err == nil {
			return t, nil
		}
	}
	return time.Time{}, nil
}

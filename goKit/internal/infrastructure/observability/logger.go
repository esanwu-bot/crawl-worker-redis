// Package observability 提供轻量日志能力（控制台 + 文件双写）。
package observability

import (
	"fmt"
	"io"
	"os"
	"path/filepath"
	"sync"
	"time"
)

// Logger 同时向标准输出与日志文件写结构化可读日志。
type Logger struct {
	mu   sync.Mutex
	out  io.Writer
	file *os.File
}

// New 创建日志器；logDir 不存在时自动创建，name 为文件名（不含扩展名）。
func New(logDir, name string) *Logger {
	l := &Logger{out: os.Stdout}
	if logDir == "" {
		return l
	}
	if err := os.MkdirAll(logDir, 0o755); err != nil {
		return l
	}
	f, err := os.OpenFile(filepath.Join(logDir, name+".log"), os.O_CREATE|os.O_APPEND|os.O_WRONLY, 0o644)
	if err != nil {
		return l
	}
	l.file = f
	return l
}

// Close 关闭底层日志文件。
func (l *Logger) Close() {
	l.mu.Lock()
	defer l.mu.Unlock()
	if l.file != nil {
		_ = l.file.Close()
		l.file = nil
	}
}

// Info 记录 INFO 级日志。
func (l *Logger) Info(format string, args ...any) { l.write("INFO", format, args...) }

// Warn 记录 WARN 级日志。
func (l *Logger) Warn(format string, args ...any) { l.write("WARN", format, args...) }

// Error 记录 ERROR 级日志。
func (l *Logger) Error(format string, args ...any) { l.write("ERROR", format, args...) }

func (l *Logger) write(level, format string, args ...any) {
	line := fmt.Sprintf("[%s] %s %s\n", time.Now().Format("2006-01-02 15:04:05"), level, fmt.Sprintf(format, args...))
	l.mu.Lock()
	defer l.mu.Unlock()
	if l.out != nil {
		_, _ = io.WriteString(l.out, line)
	}
	if l.file != nil {
		_, _ = io.WriteString(l.file, line)
	}
}

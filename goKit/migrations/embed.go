// Package migrations 以 embed 方式内置 SQL 迁移文件，
// 使编译产物无需携带外部 .sql 即可自动建表（对齐 PHP 版“首次运行自动建库建表”）。
package migrations

import "embed"

// FS 内置全部 *.sql 迁移文件。
//
//go:embed *.sql
var FS embed.FS

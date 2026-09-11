package shikues

import (
	"regexp"
	"strconv"
	"strings"

	"crawlkit/internal/adapter/coerce"
)

// specKeys 站点参数列（a..t），a 为型号，其余为参数明细。
var specKeys = []string{"b", "c", "d", "e", "f", "g", "h", "i", "j", "k", "l", "m",
	"n", "o", "p", "q", "r", "s", "t"}

// modelRow 是清洗后的中间行。
type modelRow struct {
	Model     string
	RemoteID  int
	TypeID    int
	TypeName  string
	Package   string
	PDF       string
	Specs     map[string]any
	SourceURL string
	Raw       map[string]any
}

// toModelRows 把站点行数据清洗为模型行：
//   - Model     = a（站点语义固定为 TYPE/型号列）
//   - Specs     = b..t 参数明细，按站点原 key 保留，天然支持不同系列字段异构
//   - Package   = 参数列中形态上像封装的第一个值
func toModelRows(items []any, typeID int, typeName, sourceURL string) []modelRow {
	rows := make([]modelRow, 0, len(items))
	for _, it := range items {
		item := coerce.ToMap(it)
		if item == nil {
			continue
		}
		model := coerce.Trim(item["a"])
		if model == "" {
			continue
		}
		specs := map[string]any{}
		for _, k := range specKeys {
			if v, ok := item[k]; ok && coerce.Trim(v) != "" {
				specs[k] = coerce.Trim(v)
			}
		}
		pkg := ""
		for _, k := range specKeys {
			v, ok := specs[k]
			if !ok {
				continue
			}
			if looksLikePackage(coerce.ToString(v)) {
				pkg = coerce.ToString(v)
				break
			}
		}
		rows = append(rows, modelRow{
			Model:     model,
			RemoteID:  coerce.ToInt(item["id"]),
			TypeID:    typeID,
			TypeName:  typeName,
			Package:   pkg,
			PDF:       coerce.Trim(item["pdf"]),
			Specs:     specs,
			SourceURL: sourceURL,
			Raw:       item,
		})
	}
	return rows
}

var (
	pureAlphaRe = regexp.MustCompile(`^[A-Za-z]{2,}[A-Za-z0-9]{0,10}$`)
	packageRe   = regexp.MustCompile(`^(TO|SOD|SOT|SMA|SMB|SMC|DO|DFN|QFN|LL|MSOP|TSSOP|TSOT|SC|USON|X2)[-]?[0-9A-Za-z]{1,10}$`)
)

// looksLikePackage 封装名形态启发式：排除纯数值与“数值+单位”（如 50 / 40V / 1.5A）。
func looksLikePackage(v string) bool {
	v = strings.TrimSpace(v)
	if v == "" {
		return false
	}
	if _, err := parseFloat(v); err == nil {
		return false
	}
	if pureAlphaRe.MatchString(v) {
		return true
	}
	return packageRe.MatchString(v)
}

func parseFloat(s string) (float64, error) {
	return strconv.ParseFloat(strings.TrimSpace(s), 64)
}

# -*- coding: utf-8 -*-
"""
生成 / 更新 设计文档/CHANGELOG.md（每次发版跑一次即可）

用法（在仓库根目录执行，需已安装 python）：
    python .tools/gen_changelog.py

规则：
  - 读取全部 git 提交历史，按版本号（vX.Y.Z 开头）聚合；
  - 一条提交含多类变更时按分号拆成子项各自归类；
  - 分类：安全 / 修复 / 新增 / 改进 / 重构 / 移除；
  - 文件按版本号倒序输出（最新在上），末尾附统计。

注意：本文件只做本地文档维护（生成后随版本提交到主仓），不负责 push。
"""
import subprocess, re, os, collections, sys

REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(REPO, '设计文档', 'CHANGELOG.md')

# 分类关键词：按关键词在子项中最早出现的位置归类
CATS = [
    ('安全', ['安全', '加固', '票据', '指纹', '守卫', '防护', 'deny', '屏蔽', '过滤', '敏感操作',
              '防', '校验', '漏洞', 'CSRF', 'XSS', '越权', '权限', '拦截', '审计']),
    ('移除', ['移除', '删除', '去掉', '停用', '清理']),
    ('重构', ['剥离', '重构', '迁移', '改造', '统一', '接入', '抽离', '改由', '改为']),
    ('新增', ['新增', '增加', '加入', '支持', '实现', '提供', '引入']),
    ('修复', ['修复', '修正', '解决', '根治', '失效', '错乱', '丢失', '超时', '回退', '兼容问题', 'bug']),
    ('改进', ['优化', '提升', '样式', '交互', '调整', '改版', '完善', '美化', '精简', '收敛', '规范',
              '间距', '缝隙', '留白', '对齐', '居中', '尺寸', '宽度', '高度', '渐变', '配色']),
]


def classify(text):
    """优先规则：含剥离/迁移/重构/改造的条目本质是一次结构改造，
    避免被句内的「过滤/安全」等词抢走类别（如"敏感词过滤剥离为插件"应归重构）。"""
    for k in ('剥离', '迁移', '重构', '改造', '抽离'):
        if k in text:
            return '重构'
    best = (len(text), '改进')
    for cat, keys in CATS:
        for k in keys:
            p = text.find(k)
            if 0 <= p < best[0]:
                best = (p, cat)
    return best[1]


def main():
    log = subprocess.run(['git', 'log', '--reverse', '--format=%h|%ad|%s', '--date=format:%Y-%m-%d'],
                         cwd=REPO, capture_output=True, text=True).stdout
    versions, cur = [], None
    for line in log.strip().split('\n'):
        parts = line.split('|', 2)
        if len(parts) < 3:
            continue
        date, subj = parts[1], parts[2].strip()
        m = re.match(r'^(v\d+\.\d+\.\d+)\s*(.*)$', subj)
        if m:
            ver, desc = m.group(1), m.group(2).strip()
            cur = {'ver': ver, 'date': date, 'cats': collections.defaultdict(list)}
            versions.append(cur)
        elif cur is None or re.match(r'^(Initial|chore|Merge)', subj):
            continue
        else:
            desc = subj
        for piece in re.split(r'[；;]', desc):
            piece = piece.strip().lstrip('①②③④⑤⑥⑦⑧⑨').strip()
            if piece:
                cur['cats'][classify(piece)].append(piece)

    merged = collections.OrderedDict()
    for v in versions:
        d = merged.setdefault(v['ver'], {'ver': v['ver'], 'date': v['date'], 'cats': collections.defaultdict(list)})
        for cat, items in v['cats'].items():
            d['cats'][cat].extend(items)

    ORDER = ['安全', '修复', '新增', '改进', '重构', '移除']
    L = ['# 更新日志（CHANGELOG）', '',
         '> 版本以根目录 `VERSION` 文件为准（发版只改 VERSION，避免缓存参数滞留旧版本）。',
         '> 本文件由 `.tools/gen_changelog.py` 依据提交历史生成，按版本号倒序聚合，',
         '> 类型：**安全 / 修复 / 新增 / 改进 / 重构 / 移除**。每次发版执行该脚本即可更新。', '']
    counts = collections.Counter()
    for v in reversed(list(merged.values())):
        L.append('## %s（%s）' % (v['ver'], v['date']))
        L.append('')
        for cat in ORDER:
            for it in v['cats'].get(cat, []):
                L.append('- **%s**：%s' % (cat, it.rstrip('；;')))
                counts[cat] += 1
        L.append('')
    L += ['---', '',
          '**统计**：共 %d 个版本、%d 条变更 —— ' % (len(merged), sum(counts.values()))
          + '、'.join('%s %d' % (c, counts[c]) for c in ORDER if counts[c]), '']
    when = subprocess.run(['git', 'log', '-1', '--format=%ad', '--date=format:%Y-%m-%d %H:%M'],
                          cwd=REPO, capture_output=True, text=True).stdout.strip()
    L += ['_生成时间：%s_' % when, '']

    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    with open(OUT, 'w', encoding='utf-8') as f:
        f.write('\n'.join(L))
    print('已更新 %s' % OUT)
    print('版本数: %d  变更条数: %d' % (len(merged), sum(counts.values())))
    print('分类统计:', {c: counts[c] for c in ORDER if counts[c]})


if __name__ == '__main__':
    sys.exit(main())

#!/usr/bin/env bash
# Owlsgo-Chat → phpstudy 运行副本同步 + md5 复核（与 v3 论坛同样的工作方式）
# 用法：bash "D:/Project files/Owlsgo-Chat/.tools/sync-www.sh"
#
# 说明：
#   - 排除 data/（SQLite 库与安装锁）与 uploads/（用户上传），避免 /MIR 删掉运行数据
#   - 只同步 git 跟踪的文件，副本里的运行数据不受影响
set -u
export PATH="/c/Users/zeali/.workbuddy/binaries/PortableGit/versions/1.2.0/usr/bin:/c/Windows/System32:/usr/bin:/bin:$PATH"
export MSYS_NO_PATHCONV=1

SRC="D:\\Project files\\Owlsgo-Chat"
DST="D:\\Program Files (x86)\\phpstudy_pro\\WWW\\Owlsgo-Chat"
DST_HOST="/d/Program Files (x86)/phpstudy_pro/WWW/Owlsgo-Chat"

robocopy "$SRC" "$DST" /MIR /XD data uploads .git .workbuddy .birdview .tools /NFL /NDL /NJH > /dev/null
RC=$?
if [ "$RC" -gt 7 ]; then echo "robocopy 失败 exit=$RC"; exit "$RC"; fi

FAIL=0; N=0
cd "/d/Project files/Owlsgo-Chat" || exit 1
for f in $(git ls-files); do
  # 与上方 robocopy 的 /XD 保持一致：运行数据与内部资料不参与同步校验
  case "$f" in
    data/*|uploads/*|.git/*|.workbuddy/*|.birdview/*|.tools/*) continue ;;
  esac
  A=$(md5sum "$f" 2>/dev/null | cut -d' ' -f1)
  B=$(md5sum "$DST_HOST/$f" 2>/dev/null | cut -d' ' -f1)
  if [ "$A" != "$B" ]; then echo "DIFF: $f"; FAIL=1; fi
  N=$((N+1))
done
echo "核对 $N 个跟踪文件"
if [ "$FAIL" -eq 0 ]; then echo "SYNC OK：全部一致"; else echo "SYNC FAIL：存在差异，见上"; exit 1; fi

# 同步后重载 Nginx（失败时提示用 phpstudy 面板重启）
cd "/d/Program Files (x86)/phpstudy_pro/Extensions/Nginx1.16.1" || exit 0
./nginx.exe -t && ./nginx.exe -s reload || echo "提示：Nginx 重载失败（权限不足时请在 phpstudy 面板点「重启」）"

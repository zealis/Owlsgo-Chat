来自[API 文档 - 图床](https://img.scdn.io/api_docs.php)
公共 API 文档
上传图片（POST）、按 ID/文件名查询元数据（GET），或按标签随机获取壁纸级图片（/api/random.php）。

重要提示：关于内容审查
不再限制任何违规内容，我们已经完善了机制。违规图片不会删除，我们将允许违规图片使用那些海外图片CDN如CLoudFlare。

即便你使用其它不允许的图片CDN你的图片被AI识别到违规后会自动替换为提示图片指引你使用指定的图片CDN访问。

因为这段时间有些傻逼博彩已警告多次不要使用默认的图片CDN但是删除多次仍不悔改所以彻底升级了机制。

API 端点
基础 URL 均为：

/api/v1.php

POST 上传图片

请求体使用 multipart/form-data 或 application/x-www-form-urlencoded。文件字段名为 image，或使用 image_url 由服务端经代理拉图（二者二选一）。详见下文「请求参数」。

GET 查询单张图片公开元数据

查询字符串参数 q：填写数字图片 ID或上传时的完整文件名（精确匹配）。成功时 data 中含标签数组、storage_backend（local / telegram / r2） / storage_location（存储位置）、content_description（画面描述）及 content_desc_updated_at（描述更新时间）；详见本节示例。

图片元数据查询（GET）
GET /api/v1.php?q=图片ID或完整文件名

参数	必需	说明
q	是	纯数字（图片主键 ID）或仅含英文字母、数字、下划线、点、连字符的完整文件名（与数据库一致）。不支持模糊搜索。
隐私：响应中不会返回上传者原始 IP，仅返回打码后的展示串（如 IPv4 为首段与末段可见）；归属地由服务端根据原始 IP 推算，调用方无法据此还原完整 IP。

限流：GET 与 POST 使用不同限流桶；GET 为短窗口 5 秒 / 5 次、长窗口 60 秒 / 120 次（与上传相同数值，互不占用上传配额）。超额返回 429。

成功响应示例：
{
  "success": true,
  "data": {
    "id": 12345,
    "filename": "6640c49c7161b_1715519644.webp",
    "original_filename": "screenshot.png",
    "original_size_bytes": 102400,
    "compressed_size_bytes": 20480,
    "size_display": "100 KB → 20 KB",
    "current_hash": "5317363b47c0d13925879da847c47f09da52ddf28fe7a016819c14b2732862b8",
    "upload_date": "2026-04-30 17:53:59",
    "last_accessed": "2026-04-30 18:15:06",
    "uploader_masked": "202.*.*.165",
    "location": "中国 山东 济南市",
    "tags": "男生、文本",
    "tags_array": ["男生", "文本"],
    "tag_updated_at": "2026-04-30 20:10:00",
    "content_description": "画面为浅色背景上的文字与界面截图示例。",
    "content_desc_updated_at": "2026-05-01 08:30:00",
    "storage_backend": "local",
    "storage_location": "默认",
    "image_url": "https://cdn.example.com/i/6640c49c7161b_1715519644.webp",
    "cdn_domain": "cdn.example.com",
    "password_protected": false
  }
}
last_accessed 若从未记录则为 JSON null。tag_updated_at 为标签最后更新时间，无记录或未写入时为 null。content_description 为服务端通过多模态模型生成的简短中文画面说明；尚未生成或未写入时为 JSON 空字符串 ""。content_desc_updated_at 为描述最后更新时间（Y-m-d H:i:s），无时为 null（由定时任务写入，本站不承诺实时）。storage_backend 为 local / telegram / r2；storage_location 为对应的中文展示「默认」/「Telegram」/「Cloudflare R2」。image_url 为可直接用于 <img src> 或过浏览器打开的外链（与上传 API 返回规则一致，不含管理员免密参数）。cdn_domain 为该 URL 的主机名。password_protected 为 true 时表示加密图路径为 /p/，直连可能返回鉴权页而非图片二进制。

失败响应示例（未找到）：
{
  "success": false,
  "error": "未找到与所给条件匹配的图片。",
  "message": "未找到与所给条件匹配的图片。"
}
HTTP 状态码为 404。

本站前端「查询图片信息」弹窗与第三方调用相同，均使用 GET /api/v1.php?q=。

随机图片（GET）
GET /api/random.php

从可探索公开图中随机返回一张图片，适合用作随机壁纸、网页背景或 <img src>。默认以 302 重定向跳转到图片外链（类似 Bing 每日壁纸）；加 format=json 可获取元数据。

未指定敏感类标签时，自动排除色情、违规等内容，规则与探索页一致。

参数	必需	说明
tag	否	按标签筛选；支持英文逗号分隔多标签（AND 关系，须同时包含）。不传则从全部可探索公开图中随机。
format	否	默认省略或 redirect：302 跳转到图片 URL；json：返回 JSON 元数据。
cdn_domain	否	仅在该 CDN 域名下的图片中随机；__default__ 表示未指定 CDN 的默认图。
限流：独立桶 random_long / random_short：短窗口 5 秒 / 30 次，长窗口 60 秒 / 300 次（与上传、元数据查询互不占用）。响应含 Cache-Control: no-store，避免浏览器缓存同一张图。

<!-- 网页随机背景，每次刷新换一张 -->
<img src="https://img.scdn.io/api/random.php?tag=风景" alt="随机壁纸">

<!-- 全库随机 -->
<img src="https://img.scdn.io/api/random.php" alt="随机图">
curl 获取重定向目标：
curl -s -o /dev/null -w '%{redirect_url}\n' "https://img.scdn.io/api/random.php?tag=风景"
JSON 响应示例（format=json）：
{
  "success": true,
  "data": {
    "id": 12345,
    "filename": "6640c49c7161b_1715519644.webp",
    "image_url": "https://img.scdn.io/i/6640c49c7161b_1715519644.webp",
    "tags": "风景、自然",
    "tags_array": ["风景", "自然"],
    "content_description": "山峦与云海的自然风光。"
  }
}
失败响应（无匹配图片）：
{
  "success": false,
  "error": "未找到符合该标签条件的可探索图片。",
  "message": "未找到符合该标签条件的可探索图片。"
}
HTTP 状态码为 404。Windows 定时壁纸等场景可将 /api/random.php?tag=壁纸 设为图片源，每次请求会随机跳转至不同外链。

上传请求参数（POST）
以下字段用于 POST 上传。image 与 image_url 二选一；其余选项字段与文件上传相同。

参数名	类型	必需	描述
image	File	二选一	要上传的图片或短视频文件。图片：jpg/jpeg、png、webp（静态/动态）、gif（静态/动态）、bmp、tiff。视频：mp4、webm、mov、avi（最长 10 秒，超出自动裁切；仅支持输出 GIF 或动态 WebP）。
服务端通过文件真实内容（magic bytes）识别类型。视频不支持密码保护；outputFormat 为 jpg/png/webp 时将报错。其他格式（如 AVIF、HEIC 等）暂不支持。与 image_url 不能同时使用。
image_url	String	二选一	远程图片或视频 URL（仅 http / https）。服务端经 SOCKS5 代理下载后走与文件上传相同的校验、压缩、秒传与入库流程；支持格式与限制同 image 字段。内网地址会被拒绝。
与 image 不能同时使用。也可使用 application/x-www-form-urlencoded 提交本字段。
outputFormat	String	否	输出格式（auto, jpg, jpeg, png, webp, gif, webp_animated），默认为 auto。
auto：静态图转 webp；动图/视频转 webp_animated。
webp：输出静态 WebP；若输入为动图，会自动升级为 webp_animated。
gif / webp_animated：输出动图；视频上传仅允许此二者或 auto。
视频会经 compress 服务转码压缩（限宽、降帧、有损质量），响应含 originalSize / compressedSize。
password_enabled	String	否	设为 "true" 以启用密码保护。必须与 image_password 参数一同使用。
image_password	String	否	为图片设置的访问密码或答案。当 password_enabled 为 "true" 时此项为必需。
password_type	String	否	密码模式：plain（普通密码，默认）或 qa（问答式密码）。选 qa 时必须同时提供 password_question。
password_question	String	否	问答密码的问题文本，最多 150 个字符。仅在 password_type=qa 时生效；访问者会看到该问题，并需要填写与 image_password 一致的答案才能查看图片。
cdn_domain	String	否	指定图片外链使用的CDN域名。具体可用域名请参考下方的“可用 CDN 域名”列表。如果不指定或传入 default，将使用系统默认域名。
storage_destination	String	否	存储位置：local（默认）、telegram 或 r2（Cloudflare R2）。
可用 CDN 域名
您可以在上传时通过 cdn_domain 参数指定以下任意一个域名。如果不指定，系统将自动选择。

名称	域名
失控的防御系统-海外	img.scdn.io
CloudFlare-海外	cloudflareimg.cdn.sn
EdgeOne-海外	edgeoneimg.cdn.sn
ESA-大陆	esaimg.cdn1.vip
CloudFlare(CN优选)-海外	cloudflarecnimg.scdn.io
失控的防御系统(Anycast)-海外	anycastimg.scdn.io
EdgeOne-大陆	edgeoneimg.cdn1.vip
响应格式
API 的响应统一为 JSON 格式，Content-Type: application/json; charset=utf-8。

所有响应字段说明：success 标识成败；成功响应中 url 和 data.url 都会返回可访问的图片链接， data 对象还包含 filename、storage_backend（local / telegram / r2）、压缩统计等； 失败响应同时包含 error 与 message 两个字段（内容一致，便于客户端任选其一读取）。

成功响应示例（公开图片）：
{
  "success": true,
  "url": "https://img.scdn.io/i/6640c49c7161b_1715519644.webp",
  "data": {
    "url": "https://img.scdn.io/i/6640c49c7161b_1715519644.webp",
    "filename": "6640c49c7161b_1715519644.webp",
    "storage_backend": "local",
    "original_size": 102400,
    "compressed_size": 20480,
    "compression_ratio": 80
  }
}
成功响应示例（带密码加密图）：
{
  "success": true,
  "url": "https://img.scdn.io/p/unique_encrypted_id_string.webp",
  "data": {
    "url": "https://img.scdn.io/p/unique_encrypted_id_string.webp",
    "filename": "unique_encrypted_id_string.webp",
    "storage_backend": "local",
    "original_size": 102400,
    "compressed_size": 20480,
    "compression_ratio": 80
  }
}
加密图 URL 以 /p/ 开头，访问时必须提供正确密码。返回的 URL 不会附带任何免密凭证。

秒传成功响应示例：
{
  "success": true,
  "url": "https://img.scdn.io/i/existing_image_name.webp",
  "message": "图片已存在，秒传成功！",
  "data": {
    "url": "https://img.scdn.io/i/existing_image_name.webp",
    "message": "图片已存在，秒传成功！",
    "filename": "existing_image_name.webp",
    "storage_backend": "local"
  }
}
失败响应示例：
{
  "success": false,
  "error":   "请求过于频繁，请稍后再试。",
  "message": "请求过于频繁，请稍后再试。"
}
秒传与加密上传行为说明
秒传（去重）机制
服务端以 SHA-256 哈希对上传文件做去重，命中以下任一条件会直接返回已有图片链接，不重复存储：

压缩产物命中：上传的文件本身就是此前某张图片压缩后的产物（按压缩后文件的哈希匹配）。
原图 + 输出格式命中：此前曾用同一张原图、相同 outputFormat 上传过。
秒传命中时响应的 message 字段为 "图片已存在，秒传成功！"；data.filename 为命中记录的文件名；data.storage_backend 为命中记录的实际存储（local / telegram / r2），调用方可据此展示提示或统计。

加密上传的安全约束
加密上传永远不会被秒传：每一次带 password_enabled=true 的上传都会生成新的加密文件，避免命中别人的公共或加密版本导致密码语义失效。
无密码上传只会命中公共版本：不会把某张加密图的 /p/ 链接返回给不知道密码的调用方。
返回的加密图 URL 不携带任何免密凭证：拿到 URL 的任何一方都必须凭密码访问。
代码示例
cURL
JavaScript
Python
PHP
查询图片元数据（GET）：

curl -sG --data-urlencode "q=6640c49c7161b_1715519644.webp" "https://img.scdn.io/api/v1.php"
上传公开图片:

curl -X POST -F "image=@/path/to/your/image.jpg" https://img.scdn.io/api/v1.php
通过 URL 上传（服务端经代理拉图）:

curl -X POST \
-F "image_url=https://example.com/path/to/image.jpg" \
https://img.scdn.io/api/v1.php
将GIF动图转换为WebP动图:

curl -X POST \
-F "image=@/path/to/your/animation.gif" \
-F "outputFormat=webp_animated" \
https://img.scdn.io/api/v1.php
上传加密图片（普通密码）:

curl -X POST \
-F "image=@/path/to/your/image.jpg" \
-F "password_enabled=true" \
-F "image_password=your_secret_password" \
https://img.scdn.io/api/v1.php
上传加密图片（问答式密码）:

curl -X POST \
-F "image=@/path/to/your/image.jpg" \
-F "password_enabled=true" \
-F "password_type=qa" \
-F "password_question=我养的第一只宠物叫什么？" \
-F "image_password=Tom" \
https://img.scdn.io/api/v1.php
指定CDN域名上传 (需在后台配置):

curl -X POST \
-F "image=@/path/to/your/image.jpg" \
-F "cdn_domain=img.scdn.io" \
https://img.scdn.io/api/v1.php
存储到 Telegram：

curl -X POST \
-F "image=@/path/to/your/image.jpg" \
-F "storage_destination=telegram" \
https://img.scdn.io/api/v1.php
存储到 Cloudflare R2：

curl -X POST \
-F "image=@/path/to/your/image.jpg" \
-F "storage_destination=r2" \
https://img.scdn.io/api/v1.php
错误码
状态码	描述
400 Bad Request	上传：没有上传文件、上传过程出错、或文件格式不支持。查询：缺少参数 q、或 q 格式非法（须为纯数字 ID 或允许的完整文件名字符集）。
404 Not Found	仅 GET 查询：无与 q 精确匹配的图片记录。
405 Method Not Allowed	上传接口仅支持 POST；整个 v1.php 仅支持 GET（查询）与 POST（上传）。
413 Payload Too Large	上传文件超过了所选输出格式对应的最大限制（每种格式的上限由后台配置）。
415 Unsupported Media Type	文件真实类型不在受支持的图片类型列表中（仅支持 jpg/png/webp/gif/bmp/tiff）。
429 Too Many Requests	请求过于频繁，基于客户端 IP 做双层限流：
· POST 上传使用桶名 long / short：短窗口 5 秒内最多 5 次，长窗口 60 秒内最多 120 次。
· GET 查询使用桶名 info_long / info_short：数值与上传相同，但与上传互不占用配额。
· 随机图片 /api/random.php 使用桶名 random_long / random_short：短窗口 5 秒 / 30 次，长窗口 60 秒 / 300 次。
任一窗口超额都会返回 429，并附带 Retry-After 响应头。
500 Internal Server Error	服务器内部发生错误，例如数据库连接失败、调用远端压缩服务失败等。

# 正式 HTTPS 部署設定（Stage30 Step2）

正式網域：`homefit-store.ddnsfree.com`。本文件是後續操作指引，本次沒有執行部署、DNS、憑證申請或服務操作。

## 版本與秘密

- 只用獨立 `compose.production.yaml`，不可與本機 `compose.yaml` 合併。專案名稱及 named volumes 與本機分開；app/db 沒有公開 port。
- app/web 使用同一 `RELEASE_SHA`（完整 40 位 Git SHA），GHCR owner 使用小寫。部署前確認兩張 `sha-<SHA>` Image 均存在，web 是以公開值 `VITE_API_BASE_URL=/api` 建置。不在主機 build。
- 將 `.env.example` 複製至 repository 外的私有 runtime 檔，例如 `/srv/homefit-secrets/production.env`，限制權限為 600；填入自己的 APP_KEY、DB 密碼等。APP_KEY 必須穩定保存，不能每次部署重新產生。
- 不將 runtime 檔、憑證、Registry token 加入 Git／Image／前端。不要公開完整 `docker compose config`、inspect 或 env 輸出；靜態驗證使用 `config --quiet`。
- Compose 固定 production、debug=false、HTTPS APP_URL、Secure/HttpOnly/Lax Session、host-only cookie（SESSION_DOMAIN 的字串 null 由 Laravel 解為 null）、精確 Sanctum host。XSRF cookie 需由 Axios 讀取，不設 HttpOnly；Session cookie 保持 HttpOnly。
- production CORS 檔以唯讀 bind mount 覆蓋容器內 config/cors.php，不改本機原檔。只允許正式 HTTPS origin；沒有 wildcard origin/pattern。部署必須同時帶齊此目錄及 Compose，不能只複製 YAML。

## 初始化與切換（僅於後續授權部署時執行）

下列 Linux 指令均假設工作目錄為專案根目錄，runtime 檔已由操作者安全建立。不要用本機或舊展示 Volume。先完成新 Volume／展示資料／Owner／備份等公開前門檻；本設定不會自行執行 migration、import 或帳號初始化。

1. 核對 DNS A 指向既有 Static IPv4；沒有可用 IPv6 就不要配置 AAAA。開放必要的 80/443，其他管理 port 另依主機安全策略。確認沒有舊服務占用 port。這些工作本輪未執行。
2. 預先建立 runtime 所指定的私有 HFS_TLS_DIR，目錄須存在（create_host_path=false），不得放於網站 root。保留完整 Let's Encrypt live/archive/renewal 目錄結構及 symlink；只讓 Certbot 寫入，Nginx 唯讀，私鑰不可公開。
3. runtime 的 HFS_NGINX_CONFIG 保持 `./deploy/production/nginx/acme-only.conf`。此配置無任何憑證引用，未知 Host 拒絕；正式 Host 只有 ACME challenge 可讀，其他路徑 503，不提供 HTTP 登入。依後續授權啟動三服務：

```sh
docker compose --env-file /srv/homefit-secrets/production.env -f compose.production.yaml config --quiet
docker compose --env-file /srv/homefit-secrets/production.env -f compose.production.yaml pull app web db
docker compose --env-file /srv/homefit-secrets/production.env -f compose.production.yaml up -d app web db
```

4. 先確認 ACME 路徑可由外網取得，再申請（email 由操作者替換，不能原樣執行 placeholder）：

```sh
docker compose --env-file /srv/homefit-secrets/production.env -f compose.production.yaml run --rm certbot certonly --webroot -w /var/www/acme --cert-name homefit-store.ddnsfree.com -d homefit-store.ddnsfree.com --email '<operator-email>' --agree-tos --no-eff-email
```

Certbot 為 tools profile 的短生命週期工具，普通 up 不啟動第四個常駐服務。實際 pull 可用性及憑證申請仍需部署時驗證。

5. 確認 fullchain.pem/privkey.pem 存在且有效。先以候選 HTTPS 配置測試（one-off 不發布 port；需現有 app 可解析）：

```sh
HFS_NGINX_CONFIG=./deploy/production/nginx/https.conf docker compose --env-file /srv/homefit-secrets/production.env -f compose.production.yaml run --rm --no-deps web nginx -t
```

成功後才將私有 runtime 檔的 HFS_NGINX_CONFIG 改為 `./deploy/production/nginx/https.conf`，並 recreate web；單純 restart 不會更換 bind mount 來源。

```sh
docker compose --env-file /srv/homefit-secrets/production.env -f compose.production.yaml up -d --no-deps --force-recreate web
```

切換後會公開 SPA/API，必須先完成資料及安全門檻；不能把成功 nginx -t 當成正式驗收。失敗保留 ACME-only，不用假憑證或關閉 TLS 驗證繞過。

## Laravel 設定快取與 HTTPS

- TLS 直接在 Nginx 終結，HTTPS FastCGI 參數由 `$https` 設定。PHP-FPM 沒有外部 port；不新增全信任 Proxy，也不依賴客戶端 X-Forwarded-Proto。
- Nginx 預設站拒絕未知 Host/SNI，正式站另精確檢查 HTTP Host（僅允許該協定的預設 port）。不接受 AWS IP 或其他域名作登入入口。
- healthcheck 在 web 容器內 `127.0.0.1:8080/up` 直接讀 Laravel health，不受 HTTP→HTTPS redirect 影響；8080 未發布。app 的 socket health 僅證明 FPM listener，web health 再驗 HTTP→FPM→Laravel。
- 載入 runtime env 及 production CORS mount 後建立設定快取：

```sh
docker compose --env-file /srv/homefit-secrets/production.env -f compose.production.yaml exec --user www-data app php artisan config:cache
```

- 修改 runtime env（包括 ADMIN_DEMO_ENABLED）不會自動改變既有 container/config cache。recreate app 載入新環境，重建 config cache，再 reload PHP-FPM／recreate app 清除 worker/OPcache 舊值；recreate 後再次確認 cache 值及行為，不輸出秘密。新 app Image 不得攜帶舊 config cache。
- Demo 關閉後新入口應 503，既有 Demo 管理讀取 403 DEMO_UNAVAILABLE，logout 仍有效。一般 Admin/Member 不受影響；須於部署後重新驗證。

## 自動續期（部署時建立排程）

部署操作者建立 host systemd timer 或 cron，每日執行兩次以下流程；本次不建立排程。HTTP ACME 路徑在 HTTPS 配置中保留，不應 redirect 或需登入。

```sh
docker compose --env-file /srv/homefit-secrets/production.env -f compose.production.yaml run --rm certbot renew --webroot -w /var/www/acme --quiet &&
docker compose --env-file /srv/homefit-secrets/production.env -f compose.production.yaml exec -T web nginx -t &&
docker compose --env-file /srv/homefit-secrets/production.env -f compose.production.yaml exec -T web nginx -s reload
```

先以 `renew --dry-run` 驗證，監看排程失敗／到期日；renew 成功但未更新時 reload 無害。失敗不可當續期通過；私鑰與完整憑證目錄另按安全備份策略保存。

## 尚待正式環境驗證

DNS/80/443、Image tag 存在性、兩配置 nginx -t、無憑證冷啟動、未知 Host/SNI 拒絕、HTTP 308（ACME 例外）、TLS 憑證鏈／續期、public storage HTTP、CSRF `/sanctum/csrf-cookie` 204、member/admin/Demo 登入/me/logout、Secure cookie、419／CORS、Demo kill switch，以及資源限制／1GB Swap／還原。不能用本機靜態檢查宣稱以上已通過。

HSTS/preload 暫不加入，以免憑證與續期尚未驗證就鎖定瀏覽器。Resource caps 與 FPM/DB sizing 依 Step3 量測調整；本設定尚不是 Stage30 整體完成。

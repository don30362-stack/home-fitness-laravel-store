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


## Docker 日誌輪替與故障排查

正式 app／web／db 都明確使用 `json-file`：`max-size: "10m"`、`max-file: "3"`。三份包含目前正在寫入的檔案；達上限自動輪替並移除最舊的檔案，並非保留三份歷史加一份目前檔案。每容器名目約30MB、三容器約90MB，為單筆訊息越界、metadata等至少預留120MiB空間；這不是整個Docker資料目錄硬配額，也不是按天保留。高流量時可追溯時段會縮短。

- Laravel 已指定 `LOG_CHANNEL=stderr`、`LOG_LEVEL=warning`，Monolog 寫 `php://stderr`；不另外啟用daily檔案，以免重複寫入並繞過Docker輪替。emergency fallback仍可能寫storage/logs/laravel.log，若出現要排查logger/config/cache問題，不能假設所有檔案日誌都受此限制。
- PHP-FPM 官方基底設定將error/access log送 `/proc/self/fd/2`，`catch_workers_output=yes`、`decorate_workers_output=no`，可收集worker的stdout/stderr。未改FPM Image。
- Nginx基底的access/error log分別symlink至 `/dev/stdout`／`/dev/stderr`；本專案兩個server配置沿用它們。內部healthcheck也會產生access log，已包含在web輪替預算。
- MariaDB預設console/error輸出可由Docker stderr收集；本輪唯讀檢查現有設定檔，未發現另設error檔案或啟用general/slow/binlog。未查詢DB或據此宣稱正式runtime有效值已驗證；本輪/proc/1/fd連結無法讀出，未將它算作stdout/stderr管線實證。MariaDB在Unix未指定log_error時預設寫stderr，見 https://mariadb.com/docs/server/server-management/server-monitoring-logs/error-log 。不要為排查預設開啟general/slow query log，它們可能含完整SQL及私人資料；DB binary log／資料檔若啟用，也不受Docker輪替限制。
- Certbot仍是 `run --rm` 的短生命週期工具，不包含在三個常駐容器預算；其憑證目錄與工具內檔案、DB Volume、備份包、Image、OS journal等不受這個90MB預算控制。

以下只供後續已授權的部署操作者使用；本次沒有recreate或改動現有容器。新logging設定在新建／recreate容器時套用，單純restart不能更新它；不要為此重啟Docker daemon，也不要將正式設定套到本機Compose。部署時核對三個新容器的LogConfig，勿輸出完整inspect（含Runtime秘密）。

```sh
docker compose --env-file /srv/homefit-secrets/production.env -f compose.production.yaml logs --since 15m --tail 100 --timestamps app web db
docker compose --env-file /srv/homefit-secrets/production.env -f compose.production.yaml ps
# 對每個正式容器ID各執行；不要原樣執行placeholder。
docker inspect --format '{{json .HostConfig.LogConfig}}' '<production-container-id>'
# DockerRootDir沒有帳密；確認實際data-root，勿假設一定是/var/lib/docker。
docker info --format '{{.DockerRootDir}}'
df -h /
df -i /
docker system df
```

`docker system df`不完整涵蓋container log使用量，不能用它單獨宣稱日誌占用正常。於Linux主機以實際DockerRootDir檢查檔案系統空間，必要時只做metadata容量統計（不讀取內容或操作輪替檔）：

```sh
homefit_docker_root=$(docker info --format '{{.DockerRootDir}}')
df -h "$homefit_docker_root"
sudo du -sh "$homefit_docker_root/containers"
```

containers目錄也含其他metadata，上述只是粗略容量。磁碟不足時先定位來源（log、DB、Image、備份或OS）、檢查輪替是否已套用及是否有其他檔案logger；不要手動truncate/remove Docker管理的日誌，不使用全域prune或down -v。記錄logger缺失、Permission denied、No space left、反覆重啟等訊息，依原始原因處理；輪替不能修復已滿的DB Volume。若logs看不到資料，核對service／時間範圍、driver、Laravel config cache、stdout/stderr與FPM設定；不要用dump .env或完整request來除錯。

### 日誌敏感資訊

Docker logging只負責收集／輪替，沒有自動遮罩功能。操作者與Docker權限持有人能讀取日誌；日誌只供受限維運，貼到issue／截圖／evidence前必須去識別化。

目前Nginx主設定使用combined-like格式，會記錄request URI（含query）、Referer、IP、User-Agent及X-Forwarded-For；未設定記錄request body、Cookie或Authorization。不得把密碼／token／個資放在URL或Referer，也不要增加 `$request_body`、`$http_cookie`、`$http_authorization`。任意訪客仍可能將敏感字串放進query；若要從格式上排除query／Referer，需要另行確認最小Nginx log-format補強，本輪僅Compose輪替，不自行修改Nginx或Image。

Laravel warning/error及exception context也可能包含SQL綁定值或私人資料；`APP_DEBUG=false`與warning level不代表日誌已去識別。此次只讀檢查的auth/controller、middleware與service未見直接記錄完整request或帳密的呼叫，但不是全面敏感資料保證。禁止為故障排查新增password、Cookie、Token、APP_KEY、完整會員／地址／訂單payload的log；真實日誌不納入Git或公開證據。

官方依據：https://docs.docker.com/engine/logging/drivers/json-file/ 。正式部署後仍須確認實際LogConfig及磁碟使用；本輪Compose靜態檢查不等於已在AWS套用或完成輪替壓力驗證。

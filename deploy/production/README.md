# 正式部署與維運 Runbook（Stage30 Step2）

正式網域：`homefit-store.ddnsfree.com`。本文件是後續操作指引，本次沒有執行部署、DNS、憑證申請或服務操作。

## 版本與秘密

- 只用獨立 `compose.production.yaml`，不可與本機 `compose.yaml` 合併。專案名稱及 named volumes 與本機分開；app/db 沒有公開 port。
- app/web 使用同一 `RELEASE_SHA`（完整 40 位 Git SHA），GHCR owner 使用小寫。部署前確認兩張 `sha-<SHA>` Image 均存在，web 是以公開值 `VITE_API_BASE_URL=/api` 建置。不在主機 build。
- 將 `.env.example` 複製至 repository 外的私有 runtime 檔，例如 `/srv/homefit-secrets/production.env`，限制權限為 600；填入自己的 APP_KEY、DB 密碼等。APP_KEY 必須穩定保存，不能每次部署重新產生。
- 不將 runtime 檔、憑證、Registry token 加入 Git／Image／前端。不要公開完整 `docker compose config`、inspect 或 env 輸出；靜態驗證使用 `config --quiet`。
- Compose 固定 production、debug=false、HTTPS APP_URL、Secure/HttpOnly/Lax Session、host-only cookie（SESSION_DOMAIN 的字串 null 由 Laravel 解為 null）、精確 Sanctum host。XSRF cookie 需由 Axios 讀取，不設 HttpOnly；Session cookie 保持 HttpOnly。
- production CORS 檔以唯讀 bind mount 覆蓋容器內 config/cors.php，不改本機原檔。只允許正式 HTTPS origin；沒有 wildcard origin/pattern。部署必須同時帶齊此目錄及 Compose，不能只複製 YAML。

## 初始化與切換（僅於後續授權部署時執行）

下列 Linux 指令均假設工作目錄為專案根目錄，runtime 檔已由操作者安全建立。不要用本機或舊展示 Volume。本節只說明 ACME/TLS 技術切換，完整首次部署順序以後文「正式操作 Runbook」為準；啟動 ACME-only 後，先完成 migration／展示資料／Owner 等門檻，再申請憑證及開放 HTTPS。本設定不會自行執行 migration、import 或帳號初始化。

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

- 修改 runtime env（包括 ADMIN_DEMO_ENABLED）不會自動改變既有 container/config cache。先 recreate app 載入新環境，再於該容器建立 config cache，最後 restart 同一 app 容器清除 FPM／OPcache 舊值。不要在建立 cache 後再次 recreate：bootstrap/cache 未掛 Volume，recreate 會捨棄該 writable layer；restart 保留它，但不能載入新 Compose env。整段操作保持公開入口關閉，重啟後重新核對有效設定及行為，不輸出秘密。新 app Image 不得攜帶舊 config cache；CLI 的 OPcache reset 不能當作 FPM cache 已重置的證據。
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

HSTS/preload 暫不加入，以免憑證與續期尚未驗證就鎖定瀏覽器。目前已加入下述初始Resource caps與FPM/DB sizing；仍須依Step3 AWS量測調整，本設定尚不是Stage30整體完成。


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


## Lightsail 2GB RAM 初始資源配置

本節為使用者確認的部署前初始值，不是AWS實測或不會OOM的保證。只使用正式Compose，沒有CPU硬quota或mem_reservation，也沒有修改本機Compose或Image。

| 常駐容器 | mem_limit | 內部設定 |
| --- | --- | --- |
| app | 512m（512MiB） | PHP memory_limit=128M、OPcache memory_consumption=128MiB |
| db | 768m（768MiB） | InnoDB Buffer Pool256M、max_connections20、Aria cache32M、key buffer16M |
| web | 96m（96MiB） | 保留既有Nginx／TLS配置 |

上限合計1376MiB；按主機2048MiB估算，剩672MiB給Ubuntu／Kernel／Docker／page cache及短任務。可暫按系統與Docker384MiB、單一短任務192MiB、額外96MiB餘裕規劃；不是實測消耗或預先保留，實際MemTotal可能較少。三容器同時到上限時餘裕偏緊，不能持續貼近上限運作。

### PHP／MariaDB 配置載入

- PHP新檔唯讀掛載至 `/usr/local/etc/php/conf.d/zzz-homefit-resources.ini`，排序在Image既有 `zz-home-fitness-opcache.ini`／uploads之後。只明確設定memory_limit與OPcache容量；不重複設定enable或validate_timestamps，保留既有OPcache啟用及validate_timestamps=0。
- FPM保留ondemand、max_children=2、process_idle_timeout=10s、max_requests=500。兩worker並非完整記憶體上限；PHP memory_limit不是RSS硬限制，共享OPcache、native配置、master與CLI另計。
- MariaDB新檔唯讀掛載 `/etc/mysql/conf.d/zz-homefit-resources.cnf`，使用 `[mysqld]`。本輪唯讀確認現有官方11.4同基底的my.cnf確實include此目錄（在mariadb.conf.d之後）。保留官方其他配置，不覆蓋整個conf.d目錄。
- Linux部署檔案保持0644／不可world-writable，MariaDB可能忽略world-writable設定；設定檔不得放密碼。bind mount設create_host_path=false，來源缺少時拒絕啟動。
- 不更改交易隔離、flush耐久性、FK、鎖定或帳號規則。Buffer Pool不包含全部DB記憶體，其他全域cache、連線、排序／暫存表與備份另計。20條連線是初始上限，不是20條都能同時執行重型SQL的容量保證。
- 這些Runtime掛載無需重建GHCR Image；新建／recreate正式容器才會套用mount與cgroup限制。檔案內容更新後相應PHP-FPM／DB程序也須重新載入；本輪不操作現有服務，不保證舊程序自動生效。部署時再核對PHP CLI與FPM各自有效設定。

### Swap：尚未設定個別容器memswap_limit

1GB host Swap尚未在AWS建立；只作短暫OOM緩衝，不增加實體RAM，也不應作穩態容量。頻繁swap-in/out會放大IO、DB鎖等待與Checkout延遲。

`mem_limit`是RAM限制；明確的`memswap_limit`表示RAM＋Swap總額，不是「額外Swap」。本次不寫memswap_limit、mem_swappiness或oom_kill_disable。**省略memswap_limit不代表禁止Swap**：依Docker文件，在支援Swap且host有Swap時，通常允許額外等於memory limit的Swap，仍受host共享容量限制。

| 容器 | RAM限制 | 省略memswap_limit時可能允許的額外Swap | RAM＋Swap名目總額 |
| --- | --- | --- | --- |
| app | 512MiB | 512MiB | 1024MiB |
| db | 768MiB | 768MiB | 1536MiB |
| web | 96MiB | 96MiB | 192MiB |
| 合計 | 1376MiB | 1376MiB | 2752MiB |

這不是新增1376MiB Swap：host只有預計1024MiB，而且系統／短任務也共用；各容器無法保證同時取得表中Swap。個別容器是否禁用／限額Swap及具體memswap_limit，列為AWS實測前待決門檻，必須核對Docker/cgroup Swap支援與實際HostConfig.MemorySwap後再決定，不可將本表當明確已套用的Swap配置。

官方語意：https://docs.docker.com/reference/compose-file/services/#memswap_limit 。不要停用OOM killer；容器被OOM kill仍可能發生於host尚有空間時，因為先碰到容器限制。

### 部署後觀察與驗收（指令供後續授權，這次未執行）

```sh
docker compose --env-file /srv/homefit-secrets/production.env -f compose.production.yaml stats --no-stream
free -m
vmstat 1 10
# 對每個正式容器ID執行，僅輸出必要欄位，不用完整inspect。
docker inspect --format 'RAM={{.HostConfig.Memory}} RAMplusSwap={{.HostConfig.MemorySwap}} OOM={{.State.OOMKilled}} Restarts={{.RestartCount}}' '<production-container-id>'
# 核心訊息可能含程序資訊，僅於受限維運終端檢視。
sudo journalctl -k --since '1 hour ago' | grep -Ei 'out of memory|oom|killed process'
```

代表性操作期間建議MemAvailable維持至少300MiB；持續低於200MiB或容器用量長期超過上限80～85%需調查。要求OOM／非預期restart=0、無持續swap-in/out、無新502／504；這些是初始驗收門檻，不是本輪已通過結果。docker stats與host memory統計口徑不同（cache／共享頁等），需一起看。

使用正式受限DB登入方式（互動輸入、不把密碼放CLI參數），後續只讀確認：

```sql
SHOW GLOBAL VARIABLES WHERE Variable_name IN ('innodb_buffer_pool_size','max_connections','aria_pagecache_buffer_size','key_buffer_size');
SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected','Threads_running','Max_used_connections','Connection_errors_max_connections','Innodb_buffer_pool_reads','Innodb_buffer_pool_read_requests');
```

DB無Too many connections、異常長交易或持續鎖等待；FPM不持續排隊／反覆max_children reached。FPM目前未配置status endpoint，不可假稱已有queue數據；需要時另外設內部限定監控，不能公開請求資訊。觀察Checkout／取消／庫存調整的正常回應與一致性，不以增加workers／連線數掩蓋鎖等待。

備份、PHP CLI／Artisan、壓縮與Certbot會競爭主機記憶體。exec進app的CLI共用512MiB cgroup，exec進db的dump也共用768MiB；host或其他容器執行則另占主機預算。兩個FPM worker加CLI仍可能超限。維運一次一個重型任務、串流dump／壓縮、避開尖峰；不在Lightsail進行npm／Composer build。Certbot只有192MiB的估計短任務預算，尚未設定硬限制，也不保證實際夠用。

舊原生DB與Docker Lab須依既定備份／退役授權處理，不能三套DB並跑後再靠Swap補救。本輪未做AWS量測、壓力測試、備份或任何服務重建。

## 正式操作 Runbook（2026-10-11，待部署驗證）

以下是逐項審查用流程，不是可直接整段貼上執行的部署腳本。所有 AWS 操作、Migration、匯入、初始化、備份及還原均未在本次執行。每個會寫入的步驟須先核對目標、前一步結果與操作授權；任一步失敗即停止，不自動繼續、不自動重試 mutation。只使用正式獨立 project，Windows 日常 DB、Docker Lab DB／Volumes 都不是部署或還原目標。

### 首次部署：先關閉公開入口，最後才啟用 HTTPS

1. **主機門檻**：授權後核對 Ubuntu／CPU 架構、Static IPv4、DNS A／必要 AAAA、80/443 防火牆與占用者、舊原生／Lab 服務依賴。先確認可捨棄內容及必要備份有效，才依既定計畫退役；不因需要騰 RAM 直接停 DB。配置及驗證 1GB Swap，確認可用磁碟、記憶體與 cgroup；不要同時運行三套 DB。
2. **版本門檻**：選定完整 40 位 RELEASE_SHA；核對 GHCR app/web 兩份 CI job 都成功、兩張 Image 都可取得，平台符合 linux/amd64 主機。記錄各自 digest、來源 SHA、Compose／deploy 設定版本、MariaDB 實際版本／digest及驗證時間。兩張 Image 的 digest 本來就不同，要求的是來源 SHA 相同。現行 matrix 可部分發佈成功，tag 也不是不可變保證；pull 後核對實際 RepoDigests，若和核准紀錄不符即停止。勿只因 HEAD 更新就假設該 SHA Images 已存在。
3. **秘密及 Volume 門檻**：runtime 檔位於 repository 外（例 /srv/homefit-secrets/production.env，0600）；確認穩定 APP_KEY、獨立 DB 帳密、ADMIN_DEMO_ENABLED=false、正式 HTTPS／Cookie 設定。僅記非秘密驗證結果。帶齊 readonly PHP／DB／CORS／Nginx 設定；TLS 私有目錄預建。逐一核對 project 及 app_framework、private_files、public_storage、db_data、acme_webroot 實際 Volume 名稱／掛載／所有權；固定 project 名稱不保證 Volume 是新的，若已存在且用途不明則停止，不刪除後重建。
4. **ACME-only 啟動**：採前文 config --quiet、首次 pull app web db 及 up 指令。這是唯一此流程允許同時準備 DB Image 的首次初始化階段；DB 版本須已核准。確認其他路徑 503、未知 Host 拒絕、app/db 不發布 port；尚不提供公開登入。healthcheck 不是 Schema／資料完整性證明。Migration 前 Laravel health 可能尚未可用，先確認 DB/FPM 及 exec 可用性，不能因此略過 Schema 驗證或刪資料。
5. **Schema 門檻**：核對容器內 Laravel 實際 DB host=db、port=3306、預期 DB 名稱與受限帳號；不輸出密碼或完整設定。人工確認是全新空的正式 DB、外鍵啟用且使用 InnoDB 後，才單獨執行：

```sh
# 待部署驗證；--force 只跳過 Laravel production 提示，不替代上述人工目標確認。
docker compose --env-file /srv/homefit-secrets/production.env -f compose.production.yaml exec --user www-data app php artisan migrate --force
```

以 migrate:status 與必要唯讀 Schema 查核確認完成。Migration 失敗可能已有部分 DDL 生效，不能假設整批 rollback；不得直接重跑或執行 migrate:fresh。

6. **資料及圖片**：將可信展示包受控傳入 app 的非公開 private_files，給 www-data 必要讀取權限，不放 web root；Host 路徑不會自動成為 container 路徑。以下 package 路徑必須是已確認的容器內路徑，DB placeholder 必須換成實際核對的正式名稱。先單獨預檢，審查成功後才單獨匯入：

```sh
# 待部署驗證；兩行是兩個人工確認步驟，不可作為失敗仍繼續的批次腳本。
docker compose --env-file /srv/homefit-secrets/production.env -f compose.production.yaml exec --user www-data app php artisan showcase:import /var/www/html/storage/app/private/showcase-package --database='<verified-production-db>' --check-only
```

```sh
docker compose --env-file /srv/homefit-secrets/production.env -f compose.production.yaml exec --user www-data app php artisan showcase:import /var/www/html/storage/app/private/showcase-package --database='<verified-production-db>'
```

--database 是核對條件，不會切換連線。工具要求目前 migrations 全部完成、十表及受保護業務表均空、FK 啟用、圖片無衝突；無 force／merge／overwrite。核對 10 表611筆、51圖（48商品＋3Banner）、原始 ID／關聯／排序／stock、manifest及逐檔 SHA-256；最終 HTTPS 啟用後再驗 HTTP 圖片。可捕捉例外會補償本次檔案，但斷電／kill 殘留須人工查明，不強制刪除既有圖或反覆匯入。來源封裝及完整性紀錄至少另存一份 Lightsail 外；checksum 不能證明來源可信。確認完成後受控移除部署暫存副本，不刪唯一備份。

7. **Owner**：必須匯入成功後才執行以下現有 production-only 互動指令（保留 TTY，不使用 -T／--no-interaction，不帶密碼參數）：

```sh
# 待部署驗證；由操作者私下輸入姓名、Email及隱藏密碼，不錄製輸入。
docker compose --env-file /srv/homefit-secrets/production.env -f compose.production.yaml exec --user www-data app php artisan admin:initialize-production-owner
```

確認 active Admin 與七個正式 Catalog 關聯；只留去識別結果。有任何既有 Admin 就拒絕，不重設或擴權。不得使用 local/testing 的 admin:bootstrap／admin:provision-owner。Demo 保持關閉；若後續要建立，須另核對安全建立方式、獨立身分與零 grants，不能繞過既有 CLI 的環境限制。首次 Owner 建立後，showcase:import 不再適用於該非空 DB。

8. **Runtime cache**：於最終 runtime／mount／Image 已載入的 app 容器執行前文 config:cache，成功後單獨 restart app（保留 writable layer），等待 FPM／Laravel health 恢復。OPcache validate_timestamps=0，不把 CLI 清 cache 當成 FPM 已刷新。若此後又 recreate app，須於新容器重新 cache 並 restart；不掛載舊版 bootstrap/cache 到新 Image。
9. **憑證**：依前文 ACME 路徑外網驗證、certbot certonly、fullchain／私鑰目錄及候選 HTTPS nginx -t 流程；確認真實網域及 TLS 鏈，不用自簽憑證冒充正式通過。排程及 renew --dry-run 後續實測須記錄。
10. **開放門檻**：資料、Owner、有效 config、備份位置、回退方案、資源及健康檢查全部核對後，才切 HFS_NGINX_CONFIG=https.conf 並 recreate web。若首次健康驗證失敗，保持／恢復 ACME-only，不開放登入。
11. **HTTPS 驗收**：使用合成驗收帳號及受控資料驗會員／Admin登入、me、logout，/sanctum/csrf-cookie 204、同源 /api、Secure／HttpOnly／SameSite及419行為；商品／51圖 HTTP、storage限制、CSRF、Host/SNI、Healthcheck。Demo入口關閉503、既有Demo管理讀取403 DEMO_UNAVAILABLE且logout可用，Owner／Member正常。不得將隔離假FastCGI測試當成此輪正式Laravel驗收。

### 安全更新：Image 與部署設定一起管理

1. 保存上一個已驗證的 app/web SHA、兩個 digest、部署設定版本、DB Image版本及 migrations 清單；秘密只記安全保存位置。留存上一版 Images，避免 rollback 時才發現無法取得。核對新版兩個 Images及平台；不要使用 latest，也不要靠可變 tag 作唯一證據。
2. 審查新舊 migrations／Schema相容性、環境變數及 mount 差異。無 Schema 變更才走純 app/web 更新；有變更須先訂停止寫入、備份與失敗復原方案，未確認不可開始。現有 Compose db 使用浮動 mariadb:11.4，DB 升級須另行驗證／授權，不納入一般更新。
3. **受控停止寫入**：可先將公開 web 切回 ACME-only並 recreate web，驗 API／登入503及既有請求已排空；HTTPS 暫不可用是此維護方式的停機代價。Laravel down 只對遵守 maintenance middleware 的請求／特定worker有效，不能阻擋已在途交易、CLI、外部 SQL、排程或其他容器。盤點並暫停所有實際寫入來源，等待進行中交易結束；不假設 queue=sync 代表永遠沒有外部寫入。未證實靜止就不做一致備份或 Migration。
4. 只於 runtime 私有檔更新 RELEASE_SHA，保留既有 DB／Volume／APP_KEY；先完成 config --quiet及雙 Image 核對。一般版本下載只用：

```sh
# 待部署驗證；不包含 db，不會主動更新其 Image。
docker compose --env-file /srv/homefit-secrets/production.env -f compose.production.yaml pull app web
```

5. 維持 ACME-only，先 recreate app 載入新版 Image／env。若已核准 Schema 變更，此時在停止寫入窗口單獨執行已審查的 migrate --force，核對結果；不可將其當所有更新必跑的無條件步驟。config:cache 成功後再 restart app，保留同一容器快取。下列為分開確認的指令，不建立自動連鎖腳本：

```sh
docker compose --env-file /srv/homefit-secrets/production.env -f compose.production.yaml up -d --no-deps --force-recreate app
```

```sh
docker compose --env-file /srv/homefit-secrets/production.env -f compose.production.yaml exec --user www-data app php artisan config:cache
```

```sh
docker compose --env-file /srv/homefit-secrets/production.env -f compose.production.yaml restart app
```

6. 核對 app health及實際 Image／有效設定；候選新 web Image 用 HTTPS nginx -t，人工確認後切回 HTTPS並 recreate web。開放前後記錄兩服務版本，避免新舊混用。若有使用 artisan down，核對 maintenance driver及共享 app_framework 中的維護狀態，只在全體門檻通過後用 artisan up解除；它不取代入口控制。
7. 驗證登入／Cookie、商品／storage、後台權限與Demo關閉、Healthcheck及合成資料的Checkout／庫存／訂單一致性；確認無OOM／異常重啟。失敗立即停止、關閉公開寫入入口、保留去秘密Log及狀態，不自動重送Checkout、不為Healthy清資料或Volume。

### 回滾決策：程式回滾不等於資料回復

| 情境 | 允許方向與停止條件 |
| --- | --- |
| A：只有 app/web 變更，Schema 向後相容 | 核對上一版仍相容目前資料與 runtime，維護窗口內把 RELEASE_SHA及需要的部署設定恢復到已驗證版本；按更新流程只 recreate app/web、重新 config:cache及 restart app，核對兩個 digest、health與代表性功能。不改 DB Volume。 |
| B：Migration 已改 Schema | 先核對實際 migrations與部分DDL結果；確認舊版相容才可採A。若不相容，禁止直接回滾Image；先保留現場，提出向前修正或隔離復原方案，等待確認。migrate:rollback 不是預設安全選項。 |
| C：已有新會員／訂單／庫存異動 | 不可把更新前dump直接覆蓋現有DB。先保護目前資料，在隔離目標分析差異與復原方案；沒有核准資料處理方案就不切換。即使Q30-08不保證訪客資料永久保存，也不等於授權任意覆蓋。 |

DB Image 降版不屬 app/web rollback；不要把舊MariaDB直接掛到較新版寫過的資料目錄。一般回滾禁止 migrate:fresh、db:wipe、down -v、Volume prune及刪除資料。上一版 Compose／bind設定同樣須審查，不將舊資源／秘密設定無條件套回。

### 備份用途及保護範圍

| 用途 | 必要內容與限制 |
| --- | --- |
| 初始展示狀態重建（Q30-08最低要求） | 可信展示包10表611筆／51圖／manifest／SHA-256、對應 migrations／Images／設定，以及重新安全初始化Owner的流程。至少一份副本安全保存在Lightsail之外。**不包含訪客會員、訂單、購物車、Owner或後續庫存變動**，不是完整營運備份。 |
| 正式網站完整復原 | 同一受控窗口的一致DB logical dump、public_storage、必要private_files、原APP_KEY與runtime設定、對應Images/digests／migrations及完整TLS live/archive/renewal資料。含個資及password hash時須加密、限制存取；不能放入公開展示包。此為需完整復原時的操作要求，不新增每日服務或付費資源。 |

- MariaDB 11.4 使用相容的 mariadb-dump／mariadb client，不假設官方Image仍有mysqldump別名。InnoDB可用 --single-transaction --quick取得交易快照並串流輸出；不涵蓋非交易表一致性，期間不可DDL（ALTER／DROP／TRUNCATE等）。核對備份表engine及範圍，不備份不相干資料庫。
- 最小命令形狀如下，**待部署驗證且不可直接執行placeholder**；須先確認client執行位置、DB目標、權限、私有輸出位置及錯誤傳遞方式。現有Compose沒有掛載下列憑證檔，不可假設host路徑存在於db容器。由操作者準備0600的client option file，秘密不得放命令列／聊天／日誌：

```sh
mariadb-dump --defaults-extra-file='<private-client.cnf>' --single-transaction --quick '<verified-db-name>'
```

- 實際執行時串流至非公開受限檔／加密串流，確認dump與壓縮／加密每一階段exit status（Linux pipeline需正確傳遞失敗），不能把零byte或截斷檔當成功。有routines／events等物件須先盤點必要選項；應用表dump不等於DB帳號／grants備份，還原帳號須獨立安全準備。
- DB快照不包含檔案Transaction。先關閉並排空業務寫入，確認CLI／排程也停止，再取DB及public/private檔案的同一恢復組；不得直接tar正在寫入的db_data當可靠logical backup。private_files僅保留真正需要的資料，匯入暫存／無關備份不盲目套回。
- APP_KEY需穩定且獨立加密保管，不每次部署重生；完整憑證目錄保留symlink結構、檔權限及私鑰保護。runtime檔、Registry憑證、DB帳密皆不出現在manifest公開摘要。
- 為備份建立去秘密的恢復組索引：時間、來源release及DB版本、Schema版本、檔案清單／SHA-256、加密方式、異地保管位置及還原結果。Checksum只驗完整性，仍須可信來源與加密；解密材料分開安全保存，不把唯一解密材料留在同一台Lightsail。
- 不新增每日排程、固定保留週期、付費Snapshot或備份框架。自動化頻率／保留政策可另作後續選項；不能藉此取消Q30-08異地初始展示包及實際還原門檻。

### 隔離還原演練（待授權執行，不覆蓋現況）

1. 明確選擇「初始展示」或「完整復原」，記錄備份來源及日期。先驗checksum、可解密及檔案清單；缺失即停止。不要拿Windows日常DB或Docker展示DB當演練目標。
2. 建立全新隔離project／network／DB／Volumes及受限帳號；核對實際DB名稱／host／port／權限與mount，不發布公開入口、不共用正式Volume或APP session。完整復原涉及個資時使用私有隔離環境、禁止寄信及排程外呼，證據去識別。
3. **初始展示**：使用對應Image完成migrations，核對空表，showcase:import先check-only再獨立匯入；還原51圖並核對hash，最後使用production Owner互動流程（需production環境，不繞過限制）。這會建立新的Owner，不是恢復既有Owner。Demo保持false。
4. **完整復原**：在全新空目標以相容MariaDB client還原經審查的dump及匹配檔案組；先檢查dump內USE／CREATE／DROP等目標指令，防止連到錯誤DB。依原Schema狀態復原，不先無條件migrate後再疊dump。恢復匹配APP_KEY／runtime並重新安全配置DB帳號；不跑showcase:import、不重跑Owner初始化來覆寫既有Admin。Session／cache不盲目復原，採讓使用者重新登入的演練目標；不要共用正式Cookie網域造成Session串用。
5. 驗證筆數／原ID／FK定義及孤兒檢查、商品庫存mode／variant／primary、51圖或該恢復組所有實檔checksum、必要private檔權限、Schema／auto_increment及Image一致性。代表性讀取、登入、圖片HTTP及合成Cart→Checkout→Cancel須於隔離環境驗證，確認扣／補庫存及快照，不將dump存在或DB可啟動算作還原成功。
6. 記錄Pass／Fail、restore時間及限制，不記密碼／個資。若有缺圖、SQL失敗或部分殘留，停止並保留來源及現場；不得force匯入、停FK、覆寫既有圖或反覆重跑。重新建立另一個空隔離目標也須精確核對所有權與授權。
7. 演練通過不等於允許覆蓋正式DB。正式切換另核對演練後的新資料差異及授權；只清理明確屬於本輪隔離資源，不用全域prune。Q30-08正式驗收至少一次實際初始展示還原，仍待Step3／4落實。

### 資源、失敗與公開前最後門檻

- app/web/db硬上限合計1376MiB；Artisan與FPM共用app512MiB，db內dump共用768MiB。主機剩餘672MiB是估算，還含OS／Docker。一次一個重任務，避免備份／壓縮／匯入／Artisan／Certbot同時執行；使用串流，不能把整份dump載入RAM。1GB Swap只是OOM緩衝，不能保證容量。
- 監看前文docker stats／free／vmstat／OOM／restart／DB連線及磁碟；資源不足、Migration／匯入失敗、health異常時先停止後續操作，阻止新寫入，保留精簡去秘密證據。不得為Healthy任意刪資料／Volume、放寬安全限制或自動重送交易。
- Step3前必須審查本Runbook、選定實際release pair／設定版本、異地展示包保管方式及還原演練安排、退役清單與備份、秘密安全準備方式；各項「實際完成」證據由Step3／4取得。HTTPS／Cookie、快取重啟、同SHA更新與相容回滾、備份可還原、資源及續期未實測前不可宣告正式部署通過。
- 本次僅文件補齊；未增加腳本、每日服務或新決策，未操作AWS／Docker／DB／DNS／GHCR。

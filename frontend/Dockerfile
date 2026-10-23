# Stage 1：Vue 前端建置
FROM docker.io/library/node:24.22.1-bookworm-slim@sha256:55fb5f5a527ea2c5c4a95f963e48ef90aa38c7b346054fdc8ee9957a2b55d205 AS frontend-build

WORKDIR /app

COPY package.json package-lock.json ./

RUN npm ci

COPY . .

ARG VITE_API_BASE_URL=/api

ENV VITE_API_BASE_URL=${VITE_API_BASE_URL}

RUN npm run build


# Stage 2：正式 Nginx 執行環境
FROM docker.io/library/nginx:1.30.6-alpine@sha256:47928bf67eb62a1863cb08d9ecb52d3e704304ba614eaee17884204677702951 AS runtime

COPY --from=frontend-build /app/dist/ /usr/share/nginx/html/

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf

EXPOSE 80
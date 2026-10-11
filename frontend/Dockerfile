# Stage 1：Vue 前端建置
FROM node:24-bookworm-slim AS frontend-build

WORKDIR /app

COPY package.json package-lock.json ./

RUN npm ci

COPY . .

ARG VITE_API_BASE_URL=/api

ENV VITE_API_BASE_URL=${VITE_API_BASE_URL}

RUN npm run build


# Stage 2：正式 Nginx 執行環境
FROM nginx:stable-alpine AS runtime

COPY --from=frontend-build /app/dist/ /usr/share/nginx/html/

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf

EXPOSE 80
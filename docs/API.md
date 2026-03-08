# Dynamic Rate Sidecar API 草案

用于管理端页面对接动态倍率规则（不改主项目 PHP）。

## 鉴权

- Header: `X-Access-Token: <SIDE_ACCESS_TOKEN>`
- `SIDE_ACCESS_TOKEN` 配置于 `dynamic-rate-addon/sidecar/.env`

## 数据结构

### Rule

```json
{
  "id": 1,
  "server_type": "vmess",
  "server_id": 10,
  "enabled": 1,
  "base_rate": "1.0000",
  "timezone": "Asia/Shanghai",
  "rules_json": [
    {"days":[1,2,3,4,5],"start":"18:00","end":"23:00","rate":2.0}
  ],
  "last_applied_rate": "2.0000",
  "updated_at": 1770000000,
  "created_at": 1770000000
}
```

## 接口

### 0) 内置管理页
- `GET /<ADMIN_ENTRY>`

说明：
- 返回 sidecar 内置管理页面。
- 未登录时显示登录页，登录成功后进入规则配置页。

### 0.1) 管理员登录
- `POST /ui/login`

请求：
```json
{"username":"admin","password":"xxxx"}
```

响应：
```json
{"data":true}
```

### 0.2) 管理员退出
- `POST /ui/logout`

### 1) 健康检查
- `GET /health`

响应：
```json
{"ok":true}
```

### 2) 按节点查询规则
- `GET /api/rules?server_type=vmess&server_id=10`

响应：
```json
{"data": { ...Rule }}
```
若不存在：
```json
{"data": null}
```

### 2.1) 节点列表
- `GET /api/nodes`
- `GET /api/nodes?server_type=vmess`

响应：
```json
{
  "data": [
    {"id":10,"name":"HK-1","rate":"1.0000","server_type":"vmess","server_id":10}
  ]
}
```

### 2.2) 规则列表
- `GET /api/rules/list`
- `GET /api/rules/list?server_type=vmess`

### 3) 批量查询规则
- `POST /api/rules/batch`

请求：
```json
{
  "items": [
    {"server_type":"vmess","server_id":10},
    {"server_type":"trojan","server_id":3}
  ]
}
```

响应：
```json
{
  "data": [ ...Rule ]
}
```

### 4) 新增或更新规则（Upsert）
- `POST /api/rules/upsert`

请求：
```json
{
  "server_type": "vmess",
  "server_id": 10,
  "enabled": 1,
  "base_rate": 1,
  "timezone": "Asia/Shanghai",
  "rules_json": [
    {"days":[1,2,3,4,5],"start":"18:00","end":"23:00","rate":2.0}
  ]
}
```

响应：
```json
{"data": true}
```

### 5) 删除规则
- `DELETE /api/rules/{id}`

响应：
```json
{"data": true}
```

## 错误响应

统一：
```json
{"error":"message"}
```

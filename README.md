我的频道：https://t.me/v2boardCJ
TG：https://t.me/vkwj2323_bot
# v2board面板、xiao面板实现动态倍率
`dynamic-rate-addon` 是 v2board/xiao 项目的“动态倍率旁路扩展”，核心目标是：

- **不修改主项目 `app/` PHP 源码**
- **不影响上游后续更新**
- 通过 **DB + worker + sidecar** 实现节点动态倍率
<img width="1848" height="812" alt="image" src="https://github.com/user-attachments/assets/dc90f168-8658-431f-bb8b-aad2c11ac208" />

---

## 项目优势

1. **升级友好**：与主程序解耦，后续拉上游更新时冲突更少。
2. **可回放**：脚本化初始化、重建、巡检，部署可重复。
3. **安全可控**：管理页面支持随机入口 + 管理员登录会话。
4. **低侵入**：仅改数据库与 addon 自身文件，不改业务主链路代码。
5. **可观察**：有冒烟检查脚本，可快速验证 API/worker 是否正常。

---

## 数据库新增内容

新增表：`v2_dynamic_rate_rule`

字段说明：

- `id`：主键
- `server_type`：节点类型（`vmess/trojan/shadowsocks/vless/tuic/hysteria/anytls/v2node`）
- `server_id`：节点 ID
- `enabled`：是否启用动态倍率（`0/1`）
- `base_rate`：基础倍率（`DECIMAL(10,2)`）
- `timezone`：时区（默认 `Asia/Shanghai`）
- `rules_json`：规则 JSON（时间段 + 倍率）
- `last_applied_rate`：最近一次实际生效倍率（`DECIMAL(10,2)`）
- `updated_at` / `created_at`：时间戳

索引：

- 唯一索引：`(server_type, server_id)`
- 普通索引：`enabled`

SQL 文件：

- `sql/001_create_dynamic_rate_rule.sql`（建表）
- `sql/002_alter_dynamic_rate_rule_precision.sql`（倍率精度统一 2 位）

---

## 脚本功能清单

### 1) 初始化 / 部署

- `scripts/one_click.sh`
  - **推荐的一键使用脚本**：自动建表、修正精度、生成/修复 sidecar 配置、执行一次倍率任务、安装 cron、启动 sidecar、冒烟检查。

- `scripts/setup_all.sh`
  - 一键初始化（基础版）：建表、执行一次 worker、安装 cron、启动 sidecar。

- `scripts/uninstall.sh`
  - 卸载/回滚：停止 sidecar、删除 cron、将节点 `rate` 恢复为 `base_rate`，可选删除规则表。

- `scripts/init_db.sh`
  - 从主项目 `.env` 读取数据库连接并执行建表 SQL。

### 2) 运行 / 调度

- `scripts/run_once.sh`
  - 手动执行一次动态倍率应用任务。

- `scripts/install_cron.sh`
  - 安装每分钟执行的 crontab（调用 `worker/apply_dynamic_rate.php`）。

### 3) 数据维护

- `scripts/normalize_precision.sh`
  - 历史规则精度清洗：将 `rules_json.rate` 统一为 2 位小数。

### 4) 验证

- `scripts/smoke_check.sh`
  - sidecar 接口连通性与读写冒烟检查。

---

## Worker / Sidecar 关键文件

- `worker/apply_dynamic_rate.php`
  - 根据当前时间计算倍率并回写节点表 `rate`。
- `worker/normalize_rules_precision.php`
  - 历史规则 JSON 精度清洗工具。
- `worker/lib/RateCalculator.php`
  - 时间段倍率计算器（支持跨天）。
- `worker/lib/EnvLoader.php`
  - 轻量 `.env` 读取。
- `sidecar/src/index.php`
  - sidecar API + 登录会话校验 + 随机入口管理页。
- `sidecar/src/admin.html`
  - 交互式规则管理页面。
- `sidecar/src/login.html`
  - 管理员登录页面。

---

## 一键使用（推荐）

```bash
bash scripts/one_click.sh /www/wwwroot/your-project
```

执行后会输出：

- 管理入口 URL（随机路径）
- 管理员账号/密码
- sidecar token

> 首次使用后请立即修改 `sidecar/.env` 中默认凭据并重启 sidecar。

---

## 一键卸载 / 回滚

仅回滚运行状态（保留规则表）：

```bash
bash scripts/uninstall.sh /www/wwwroot/your-project
```

回滚并删除规则表：

```bash
bash scripts/uninstall.sh /www/wwwroot/your-project --drop-table
```

回滚脚本会执行：

1. 停止 sidecar
2. 删除动态倍率 cron
3. 将节点倍率恢复到 `base_rate`
4. 按参数决定是否删除 `v2_dynamic_rate_rule`

---

## 分步使用（手动）

1. 初始化数据库

```bash
bash scripts/init_db.sh /www/wwwroot/your-project
```

2. 首次执行倍率任务

```bash
bash scripts/run_once.sh /www/wwwroot/your-project
```

3. 安装定时任务

```bash
bash scripts/install_cron.sh /www/wwwroot/your-project
```

4. 启动 sidecar

```bash
bash sidecar/scripts/start_sidecar.sh
```

5. 冒烟检查

```bash
bash scripts/smoke_check.sh http://127.0.0.1:8092 <SIDE_ACCESS_TOKEN>
```

---

## 规则格式（`rules_json`）

```json
[
  {"days":[1,2,3,4,5],"start":"18:00","end":"23:00","rate":2.5},
  {"days":[6,0],"start":"10:00","end":"23:59","rate":1.2}
]
```

- `days`：星期数组（`0=周日 ... 6=周六`）
- `start` / `end`：`HH:MM`
- `rate`：倍率（`>0`，保存为 2 位小数）

说明：

- 支持跨天规则（例如 `23:00-02:00`）
- 多条同时命中时按数组顺序，第一条优先

---

## 注意事项

- 本方案是“分钟级生效”，不是每次请求实时计算。


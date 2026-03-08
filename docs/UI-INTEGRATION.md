# 管理端页面对接草案

目标：在节点管理列表与编辑弹窗中增加“动态倍率”配置，不改后端 PHP。

## 页面字段

在每个节点新增：

- `dynamic_rate_enabled`（开关）
- `dynamic_rate_timezone`（默认 `Asia/Shanghai`）
- `dynamic_rate_rules`（数组）
  - `days`: `number[]`（0-6）
  - `start`: `HH:MM`
  - `end`: `HH:MM`
  - `rate`: `number > 0`

## 前端最小流程

1. 打开节点列表后，收集当前页 `server_type + server_id`。
2. 调用 `POST /api/rules/batch` 获取规则映射并合并到 UI 状态。
3. 在编辑弹窗里允许编辑上述 3 项。
4. 点击保存时，调用 `POST /api/rules/upsert`。
5. 可选：若关闭开关，仍保留规则，但 `enabled=0`。

## 校验建议

- `base_rate > 0`
- `timezone` 合法（初期可仅允许 `Asia/Shanghai`）
- 每条规则：
  - `days` 非空且仅允许 0..6
  - `start/end` 必须 `HH:MM`
  - `rate > 0`

## 文案建议

- 开关：启用动态倍率
- 说明：动态倍率由旁路任务每分钟生效，不影响主程序升级。
- 提示：若关闭，系统自动回退到 `base_rate`。

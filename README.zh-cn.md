# CRUD Skeleton

一个面向生产实践的 Symfony + Doctrine CRUD 起步模板，内置可复用的服务层抽象。

> English version: 见 `README.md`

## 目录

- [为什么使用这个项目](#为什么使用这个项目)
- [功能特性](#功能特性)
- [技术栈](#技术栈)
- [项目结构](#项目结构)
- [快速开始](#快速开始)
- [配置说明](#配置说明)
- [本地运行](#本地运行)
- [API 路由示例](#api-路由示例)
- [服务层设计说明](#服务层设计说明)
- [如何创建自己的 CRUD 模块](#如何创建自己的-crud-模块)
- [测试](#测试)
- [Docker 说明](#docker-说明)
- [常见问题](#常见问题)
- [贡献指南](#贡献指南)
- [许可证](#许可证)

## 为什么使用这个项目

这个仓库是一个干净、可扩展的后端 CRUD 基础骨架，适合快速搭建业务 API。

相比纯脚手架模板，它额外提供：

- 通用 `BaseService` 契约，统一实体 CRUD 操作。
- 可复用的 API 视图 mixin（list/detail/create/update/delete）。
- 控制器瘦身模式：业务逻辑放在服务层。
- 在不破坏外部 API 的前提下，支持内部现代化重构。

## 功能特性

- 通用 CRUD 方法：`new()`、`get()`、`list()`、`update()`、`updateWithoutListener()`、`remove()`。
- 通过请求参数控制列表查询（排序/过滤/分组/字段选择）。
- API mixin 已带 OpenAPI 属性，便于生成文档。
- 包含单元测试与集成测试。
- 自带 Docker Compose（PostgreSQL + Mailpit）。

## 技术栈

- PHP `>= 8.4`
- Symfony `8.x`
- Doctrine ORM `^3.6`
- PHPUnit `^12.5`
- PostgreSQL（通过 Docker Compose）

完整依赖请查看 `composer.json`。

## 项目结构

```text
.
├── src
│   ├── Common
│   │   ├── Controller
│   │   ├── Entity
│   │   ├── Repository
│   │   └── Service
│   └── Core
│       ├── Controller
│       ├── Service
│       │   ├── Concern
│       │   └── BaseService.php
│       └── View
├── tests
│   ├── Core
│   └── Integration
├── compose.yaml
├── compose.override.yaml
└── phpunit.dist.xml
```

## 快速开始

### 1) 克隆仓库

```bash
git clone <your-repo-url>
cd crud-skeleton
```

### 2) 安装依赖

```bash
composer install
```

### 3) 准备环境变量

建议在 `.env.local` 中覆盖本地配置：

```dotenv
APP_ENV=dev
APP_SECRET=change-me
DATABASE_URL="postgresql://app:!ChangeMe!@127.0.0.1:5432/app?serverVersion=16&charset=utf8"
```

## 配置说明

关键环境变量（参考 `.env`）：

- `APP_ENV`
- `APP_SECRET`
- `DATABASE_URL`
- `MAILER_DSN`

生产环境请不要在仓库中提交明文密钥。

## 本地运行

### 方式 A：本机运行 Symfony

```bash
symfony server:start
```

或：

```bash
php -S 127.0.0.1:8000 -t public
```

### 方式 B：使用 Docker 启动数据库

```bash
docker compose up -d
```

然后执行数据库迁移：

```bash
php bin/console doctrine:migrations:migrate
```

若你暂未维护 migration，也可按团队规范使用 schema 工具。

## API 路由示例

当前示例模块提供 `Content` 相关接口。

### App 范围（偏只读）

- `GET /api/v1/app/contents` - 列表
- `GET /api/v1/app/contents/{id}` - 详情

### Manage 范围（完整 CRUD）

- `GET /api/v1/manage/contents` - 列表
- `GET /api/v1/manage/contents/{id}` - 详情
- `POST /api/v1/manage/contents` - 创建
- `PUT /api/v1/manage/contents/{id}` - 更新
- `POST /api/v1/manage/contents/batch-update` - 批量更新/混合创建
- `DELETE /api/v1/manage/contents/{id}` - 删除

请求示例：

```bash
curl -X POST "http://127.0.0.1:8000/api/v1/manage/contents" \
  -H "Content-Type: application/json" \
  -d '{"title":"Hello","body":"World"}'
```

## 服务层设计说明

`BaseService` 已按职责拆分为 `src/Core/Service/Concern` 下的 trait：

- `BaseServiceInfrastructureTrait`
  - EntityManager/Repository/Logger/Serializer 访问
  - RequestStack 与 Validator 封装
  - ExpressionService 与 LegacyEvaluator 的延迟初始化
- `BaseServiceReadListTrait`
  - `get()` 与 `list()` 的读取逻辑
  - 基于 QueryBuilder 的列表能力，以及请求参数驱动筛选/排序/分组
- `BaseServiceMutationTrait`
  - `new()`、`update()`、`updateWithoutListener()`、`remove()`
  - 关系字段、日期字段、反射元数据处理

外部契约通过 `BaseServiceInterface` 保持不变，兼容现有调用代码。

## 如何创建自己的 CRUD 模块

常见流程：

1. 在 `src/Common/Entity` 新建 Doctrine 实体。
2. 在 `src/Common/Service` 新建服务，继承 `BaseService`。
3. 在 `src/Common/Controller` 新建控制器，组合 `src/Core/View` 中的 mixin。
4. 在控制器中定义允许输入字段（必填/可选）。

最小服务示例：

```php
<?php

namespace App\Common\Service;

use App\Common\Entity\Content;
use App\Core\Service\BaseService;
use App\Core\Service\ServiceLocatorInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ContentService extends BaseService
{
    public function __construct(ContainerInterface $container, ServiceLocatorInterface $locator)
    {
        parent::__construct($container, Content::class, $locator);
    }
}
```

## 测试

运行全部测试：

```bash
./vendor/bin/phpunit
```

运行单个测试：

```bash
./vendor/bin/phpunit tests/Core/Service/BaseServiceUnitTest.php
```

`phpunit.dist.xml` 已配置 `APP_ENV=test` 以及 `KERNEL_CLASS=App\Kernel`。

## Docker 说明

仓库包含：

- `compose.yaml` - PostgreSQL 服务
- `compose.override.yaml` - 本机端口映射与 Mailpit

默认端口：

- PostgreSQL: `5432`
- Mailpit SMTP: `1025`
- Mailpit UI: `8025`

## 常见问题

### 运行 PHPUnit 提示 PHP 版本过低

请确认当前 CLI PHP 版本满足 `composer.json` 要求（`>= 8.4`）。

### 数据库连接失败

- 检查 `DATABASE_URL`。
- 确认 PostgreSQL 正在运行。
- 确认数据库用户名、密码、库名与 compose 配置一致。

### 返回结果为空或序列化异常

检查 serializer 服务配置，以及 `@display`、`@expands`、`@filter` 等请求参数。

## 贡献指南

1. Fork 后创建功能分支。
2. 保持 PR 小而聚焦。
3. 行为变化请补充/更新测试。
4. 提交信息建议写清楚改动原因（why）。

## 许可证

当前 `composer.json` 中标记为 `proprietary`。

如果你要公开发布仓库，建议补充标准 `LICENSE` 文件并同步更新元数据。

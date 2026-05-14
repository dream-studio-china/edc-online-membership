项目名称: CRUD Skeleton

简介
----
这是一个小型、现代的 PHP CRUD 骨架工程，重点在于服务层（service layer）模式的组织，使用 Doctrine ORM 与 Symfony 组件。它适合作为构建 API 或内部服务的起点，并强调职责分离。

核心原则
--------
- 极简且实用的服务构建块。
- 明确的职责分离：基础设施、读取/列表逻辑与变更逻辑已拆分，便于测试和维护。
- 向后兼容的辅助工具（例如序列化器、请求访问封装），以便在旧式与现代 Symfony 容器中都能工作。

亮点
----
- 基于 Doctrine ORM 的仓库与查询构造器工具。
- BaseService 抽象（已拆分为小型 trait）提供常见 CRUD 模式。
- 测试中使用轻量级的假对象，支持无需启动完整内核的单元测试。

要求
----
- PHP >= 8.4
- Composer
- 本项目依赖 Symfony 和 Doctrine 的组件（见 composer.json）。如需运行集成测试，请安装开发依赖并配置数据库。

安装
----
1. 克隆仓库：

   git clone <repo-url>
   cd crud-skeleton

2. 安装依赖：

   composer install

3. 如果要运行集成测试，请准备数据库配置并执行迁移或建表。

使用说明
-------
该骨架侧重服务层。常见用法：

- 通过扩展 App\Core\Service\BaseService 并在构造函数中传入实体类，构建具体服务。
- 使用 new()/get()/list()/update()/updateWithoutListener()/remove() 方法完成 CRUD 操作。

示例（伪代码）
-------------

   $service = new ContentBaseService($container);
   $entity = $service->new();
   $service->update($entity, ['title' => 'Hello']);
   $fetched = $service->get($entity->getId());

测试
---
本仓库使用 PHPUnit 作为单元测试框架。运行测试步骤：

1. 确认本地 PHP 版本满足 composer.json 中声明的要求（通常需要 >= 8.3）。
2. 安装开发依赖： composer install --dev
3. 运行测试：

   ./vendor/bin/phpunit

若在本地运行 phpunit 遇到 PHP 版本不匹配问题，可考虑使用 phpenv、Docker 或提供所需 PHP 版本的容器镜像。

仓库结构
------
- src/ - 源代码
  - Core/Service - BaseService 与拆分的 traits 和辅助工具
- tests/ - 单元与集成测试
- composer.json - 依赖与自动加载配置

贡献指南
------
欢迎贡献。贡献流程建议：

1. 从 main 分支创建 feature 分支。
2. 保持变更小而聚焦；优先用小工具替代庞大改动。
3. 在本地运行并通过测试。
4. 提交 PR，说明变更的原因（为什么需要这次改动）。

版本与发布
---------
该仓库建议遵循语义化版本（semver）。在 GitHub Release 时提供变更日志摘要。

授权
---
composer.json 中包含了一个私有/专有许可占位。发布或分发前，请与仓库拥有者确认许可条款。

联系方式
----
如有问题或需要帮助，请在仓库中打开 issue 或联系维护者。

附录：关于 BaseService 重构的说明
-------------------------------
为了提高可维护性与可测试性，原先的单体 BaseService 被拆分为更小的 trait，按职责分组：

- Concern/BaseServiceInfrastructureTrait — 基础设施辅助（序列化器、日志、实体管理器访问等）
- Concern/BaseServiceReadListTrait — 读取/列表/查询逻辑（原有的 list/get 行为）
- Concern/BaseServiceMutationTrait — 创建/更新/删除与元数据处理

这是内部重构：保持了公共 API（BaseServiceInterface）与方法签名不变，以便现有调用方继续工作。

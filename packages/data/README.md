# firefly/data

LaraFly's data layer. Its make-or-break core is declarative `#[Transactional]`: a compile-time
`TransactionalScanner` emits a pure-array `TransactionalManifest`; a reflection-free `ProxyClassGenerator`
emits `{Target}__FireflyTransactionalProxy extends {Target}` override sources; a `ProxyFactory`
(`newInstanceWithoutConstructor` + a scope-bound state copy) instantiates the proxy; and a
`TransactionalBeanPostProcessor` swaps it in via the M4 BeanPostProcessor seam at phase 700. The
`TransactionInterceptor` delegates to a `TransactionTemplate` that drives MANUAL
`DB::connection()->beginTransaction()/commit()/rollBack()` — never `DB::transaction()` — so
`rollbackFor`/`noRollbackFor` can commit despite an exception. Reflection is confined to
`Scanner/TransactionalScanner.php` and `Proxy/ProxyFactory.php`.

Apache-2.0 © Firefly Software Solutions Inc.

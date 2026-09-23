<?php

declare(strict_types=1);

namespace Firefly\Actuator\Health;

/**
 * THE SEAM THAT LETS `when-authorized` MEAN SOMETHING, without firefly/actuator learning what a principal is.
 *
 * Spring's `management.endpoint.health.show-details: when-authorized` asks a question this package cannot
 * answer: who is asking. There is no Actuator -> Security edge in `deptrac.yaml` and there must not be —
 * actuator is a diagnostics surface that has to work in an application with no security at all — so for two
 * releases `when-authorized` degraded to `never`, which is safe and also a documented lie about what the
 * value does.
 *
 * This is the CqrsMetrics / EdaTracing shape applied to the same problem: the package that owns the QUESTION
 * declares the port and ships the conservative default, and the package that owns the ANSWER fills it. An
 * application with firefly/security installed and its master flag on gets
 * PrincipalHealthDetailsAuthorizer, which reads the session-held principal and the
 * `firefly.management.endpoint.health.roles` list; an application without it keeps
 * DenyHealthDetailsAuthorizer, which is `never` by another name — the behaviour this value has always had,
 * now as a bean an application can also override itself in five lines.
 *
 * Deliberately a QUESTION WITH NO ARGUMENTS. Handing this an EndpointRequest would invite an implementation
 * to make a different decision per endpoint path, and an authorization rule scattered across call sites is
 * how a surface ends up open on one of them. It answers one thing: may the CURRENT caller read health
 * details. Where "current" comes from is the implementer's problem, and in the shipped one it is exactly
 * where every other rule in the framework reads it from.
 */
interface HealthDetailsAuthorizer
{
    public function mayReadDetails(): bool;
}

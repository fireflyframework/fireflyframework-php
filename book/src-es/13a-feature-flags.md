<span class="eyebrow">Parte IV — Observabilidad, Pruebas y Entrega · Capítulo 13A</span>

# Feature Flags entre LaraFly y PyFly {.chtitle}

Al terminar este capítulo usarás `FeatureFlags` en Lumen, distinguirás una bandera deshabilitada del valor por defecto del llamador, asignarás una cohorte estable, operarás el almacén sin perder una transacción externa y probarás un despliegue con `withFeatureFlags()`. Los Capítulos 3, 4, 9, 11, 12 y 13 aportan la configuración, HTTP, transacciones, actuator, pruebas y CLI que utilizamos aquí.

---

## Empezar a oscuras y elegir una variante

Instala la biblioteca LaraFly publicada y activa `firefly.feature-flags.enabled` en `config/firefly.php`. El subsistema está apagado por defecto. Una bandera de configuración puede usar la abreviatura booleana o de cadena; un archivo, documento HTTP o escritura de almacén exige la definición flagd completa. `true` crea variantes booleanas `on`/`off` y selecciona `on`; `false` selecciona `off`. Una cadena como `v2` crea una sola variante de cadena. Los números y objetos no tienen abreviatura. La guía del módulo enumera todas las claves de configuración.

El despliegue incluido en Lumen conserva el texto anterior de la wallet cuando la bandera booleana está apagada y elige una vista nombrada desde una bandera multivariante:

<!-- source: samples/lumen/src/Application/WalletRollout.php -->
```php
<?php

declare(strict_types=1);

namespace Lumen\Application;

use Firefly\FeatureFlags\FeatureFlags;

final class WalletRollout
{
    public function __construct(private readonly FeatureFlags $flags) {}

    public function balanceLabel(): string
    {
        return $this->flags->isEnabled('wallet-balance-v2') ? 'Available balance' : 'Balance';
    }

    public function checkoutView(): string
    {
        return match ($this->flags->getString('wallet-checkout-view', 'legacy')) {
            'v2' => 'wallet.v2',
            default => 'wallet.legacy',
        };
    }
}
```

El arnés de pruebas de la muestra arranca `FeatureFlagsServiceProvider` y `FeatureFlagsWiringProvider`, con `wallet-balance-v2` apagada y `wallet-checkout-view` en `legacy`. Su prueba cambia ambas mediante `withFeatureFlags()`, comprueba el resultado visible, limpia el override y comprueba la restauración. El valor por defecto de `getString()` también lleva una bandera ausente o inválida a la vista anterior. `details()` devuelve valor, variante, motivo, código de error y metadatos; `getInt()`, `getFloat()` y `getObject()` solicitan otros tipos. Una bandera booleana nunca se trata como número. El estado `DISABLED` devuelve el valor del llamador con motivo `DISABLED`; una bandera ausente lo devuelve con `FLAG_NOT_FOUND`.

## Selección y cohortes estables

Una definición completa contiene `state`, `variants`, un `defaultVariant` opcional, reglas JSON Logic en `targeting` y `metadata` escalar. La regla devuelve el nombre de una variante; `null` elige la predeterminada. `$evaluators` compartidos se referencian estructuralmente mediante `{"$ref":"name"}`; una referencia ausente o cíclica produce `PARSE_ERROR` al evaluar. LaraFly y PyFly ejecutan los mismos vectores de conformidad. La prueba de Lumen consume los casos compartidos `pro-reports`: el plan `pro` resuelve `on`, el gratuito resuelve `off`, y ambos comprueban variante y motivo `TARGETING_MATCH`.

Para despliegues porcentuales, `fractional` calcula MurmurHash3 x86 de 32 bits sin signo sobre la clave de bandera y un `targetingKey` estable. La misma clave cae en el mismo grupo en ambos frameworks. El tráfico anónimo no tiene clave estable y recibe la variante predeterminada salvo que la aplicación aporte una. Usa un id de usuario autenticado u otro identificador duradero; un id de sesión cambiante mueve a las personas entre cohortes.

El contexto ambiente incluye `application`, `profiles`, nombre del principal, `roles` sin `ROLE_` y `tenant` desde el atributo configurado del principal. Un `#[Component]` que implemente `EvaluationContextContributor` puede añadir `plan`; los contribuidores posteriores prevalecen y los atributos explícitos del llamador prevalecen sobre todos. En contexto JSON, un `targetingKey` explícito admite cadena no vacía o entero; un argumento separado no vacío tiene prioridad. LaraFly descarta atributos no admitidos, incluidos objetos Eloquent/Arrayable/Jsonable y nombres de aspecto numérico, con diagnóstico DEBUG. Pasa el id del modelo, no el modelo. Fechas y horas se convierten en milisegundos desde la época UTC en ambos evaluadores.

## Proteger método, ruta y vista

`#[FeatureFlag('wallet-balance-v2')]` protege un método de bean con proxy; `fallback:` llama a un método hermano con los mismos argumentos. En una acción de controlador, el middleware rechaza antes de vincular argumentos; un fallback que requiere argumentos vinculados se ejecuta en el interceptor. Los archivos de rutas pueden usar `feature-flag:key,variant`; Blade ofrece `@featureflag` y `@featurevariant`. Una ruta rechazada devuelve el problema configurado con estado 404, 403 o 503 sin revelar la clave. La puerta permanece cerrada por defecto: una bandera ausente, deshabilitada o errónea no autoriza. Usa `default: true` solo si deseas abrirla explícitamente. Una bandera no cambia el registro de beans después del arranque; la condición de propiedad del Capítulo 3 resuelve esa decisión distinta.

El `WalletRolloutGate` incluido hace concreto el fallback; su prueba llama al bean con proxy antes, durante y después del override:

<!-- source: samples/lumen/src/Application/WalletRolloutGate.php -->
```php
<?php

declare(strict_types=1);

namespace Lumen\Application;

use Firefly\Container\Attributes\Service;
use Firefly\FeatureFlags\Gating\FeatureFlag;

#[Service]
class WalletRolloutGate
{
    #[FeatureFlag('wallet-premium-label', fallback: 'legacyLabel')]
    public function label(string $name): string
    {
        return "Welcome back, {$name}";
    }

    public function legacyLabel(string $name): string
    {
        return "Hello {$name}";
    }
}
```

## Fuentes y cambios del operador

La precedencia es configuración → archivo JSON/YAML observado → cliente HTTP de sincronización → almacén escribible → overrides de prueba. La fuente ganadora aporta la definición completa. Los fallos conservan el último documento aceptado; una configuración o archivo inicial inválido impide arrancar. Sustituye archivos de forma atómica para evitar lecturas parciales. Entrecomilla claves y valores YAML con aspecto de fecha para conservar la portabilidad JSON/PHP. El cliente remoto usa autenticación bearer y ETags condicionales; el servidor excluye overrides. Un primer 304 no solicitado es un error, mientras que un cuerpo válido sin ETag recibe una revisión SHA-256.

Asigna a cada aplicación su propio prefijo de caché y conserva las entradas de fuentes sin expulsión para que los procesos nuevos recuperen el último documento válido. La revisión del archivo usa mtime en segundos enteros y tamaño: un cambio del mismo tamaño con la misma marca temporal puede pasar inadvertido indefinidamente, incluso con renombrado atómico. Publica atómicamente y avanza mtime a un segundo distinto (o cambia el tamaño). Los avisos de cambio son de mejor esfuerzo, no duraderos ni de entrega exactamente una vez: procesos de un despliegue gradual con distinta configuración pueden alternar avisos; una caída de caché suspende los avisos compartidos y la recuperación sin registro de composición informa `startup`. Usa el historial del almacén para auditar escrituras duraderas. El token bearer del controlador de sincronización no evita los filtros externos Security HTTP/JWT de la aplicación; configura la ruta para el cliente previsto y conserva la comprobación del token.

El almacén relacional usa `firefly_feature_flags` y `firefly_feature_flag_changes`: una definición versionada y una fila de auditoría dentro de la misma transacción. `expectedVersion` detecta conflictos. Activar o cambiar la variante predeterminada de una bandera de capa inferior copia su definición efectiva al almacén; borrar el override vuelve a revelar la capa inferior. Una escritura dentro de transacción externa solo es visible tras el commit del llamador; el rollback elimina bandera y auditoría. Un aborto nativo por instantánea de MariaDB invalida la transacción principal: revierte y reintenta la operación completa. Una escritura duradera puede devolver `refreshPending` cuando el refresco local se aplaza o se rechaza; el sondeo recupera la visibilidad desde la última composición válida.

El actuator `flags` y el panel de administración ofrecen listado, detalle, vista previa, historial y escrituras protegidas. Hay que exponer el endpoint explícitamente; las escrituras necesitan además `firefly.feature-flags.management.writes` y un almacén. `firefly:flags` es la CLI correspondiente; comprueba los subcomandos de la versión instalada con su ayuda. Para `evaluate`, omite `--context` o pasa `--context='{}'` si no hay atributos explícitos; el valor suministrado debe ser texto de objeto JSON o el comando termina con código 1 y `bad-request` antes de evaluar. La vista previa solo ve el contexto enviado más `application` y `profiles`; no hereda el principal del operador ni emite métrica o evento de exposición. Un proveedor OpenFeature externo puede suministrar evaluaciones, pero el registro, almacén escribible, historial de fuentes y operaciones de gestión de Firefly necesitan su proveedor propio. No supongas que el externo ofrece estas funciones.

## Observar, retirar y probar

Cada evaluación ordinaria incrementa `feature_flag_evaluations_total` si hay un medidor disponible y `firefly.observability.metrics.enabled` está activado (valor predeterminado), con etiquetas de bandera, variante y motivo. Si las métricas están desactivadas o no hay medidor, `NoOpFeatureFlagMetrics` conserva la evaluación sin registrar el contador. Activa `events.evaluations` para publicar exposiciones `FeatureFlagEvaluated`. Un grafo de valores con más de 10 000 ocurrencias omite ese evento, pero conserva evaluación y métrica. El componente de salud `featureflags` informa del estado de las fuentes y de la deuda de banderas caducadas. Una bandera caducada sigue evaluándose; retírala al terminar el experimento o despliegue.

El `withFeatureFlags()` del Capítulo 12 instala una capa local al proceso sobre todas las fuentes y devuelve un objeto con `set()`, `forget()`, `merge()` y `clear()`. Limpia siempre un override cuando una prueba comparte la aplicación en ejecución. Las pruebas enfocadas de Lumen ejecutan el estado apagado/encendido/predeterminado y la selección compartida exacta, de modo que el listado de este capítulo tiene prueba ejecutable. Para todas las reglas de validación y de red, consulta la referencia Feature Flag Contract del sitio.

## Resumen

Elige conscientemente los valores por defecto, usa claves de selección estables, versiona los cambios del almacén y prueba tanto el despliegue como la restauración. La vista previa de gestión y la evaluación ordinaria emiten telemetría distinta; usa la vía adecuada para tu pregunta.

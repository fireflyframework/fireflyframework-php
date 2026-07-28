## Capítulo de Prueba — Sistema de Compilación

Este capítulo es andamiaje, no contenido: la Tarea B1 construyó la tubería
EPUB+PDF de `book/` (WeasyPrint, ensamblador EPUB, extensiones de Markdown,
tema visual, verificador de listados PHP) y esta página existe para probar
que cada pieza se renderiza correctamente de principio a fin. Las Tareas B2 a
B5 la reemplazan con los trece capítulos reales — Inyección de Dependencias,
Configuración, CQRS, Seguridad, y el resto.

### Un listado de código

Los capítulos reales delimitan los listados PHP con un bloque ```` ```php ````
plano, que aquí se resalta con sintaxis y además se valida en
`book/build/verify_code.py` mediante `php -l`. Este fragmento proviene del
objeto de valor de `samples/lumen`:

```php
<?php

declare(strict_types=1);

namespace Lumen\Domain;

final readonly class Money
{
    public function __construct(public int $minorUnits, public Currency $currency) {}

    public static function zero(Currency $currency): self
    {
        return new self(0, $currency);
    }
}
```

### Una figura

Los diagramas usan la directiva `::: figure` y se incrustan como SVG para que
se rendericen con nitidez tanto en el EPUB como en el PDF impreso:

::: figure art/figures/di-autoconfig.svg | Figura 0.1 — Inyección de dependencias y auto-configuración (figura de arranque, copiada de docs/assets/diagrams/)

### Recuadros

Hay cuatro estilos de recuadro disponibles. Tres provienen del sistema de
libros de PyFly; el cuarto es el recuadro propio de paridad con Laravel:

!!! note "Nota"
    Las notas aportan contexto adicional o aclaran un matiz del texto principal.

!!! tip "Consejo"
    Los consejos comparten un atajo, un idioma o una buena práctica.

!!! warning "Advertencia"
    Las advertencias señalan un error común o un punto delicado.

!!! laravel "Paridad con Laravel"
    Los recuadros de paridad con Laravel relacionan un concepto de LaraFly
    directamente con su equivalente nativo en Laravel — por ejemplo,
    `#[RestController]` junto a un controlador Laravel normal — para
    desarrolladores que llegan desde Laravel puro.

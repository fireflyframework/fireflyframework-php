## Convenciones

Esta página explica las convenciones tipográficas y estructurales que se usan a lo largo del libro.

### Listados de código

El código real se presenta en un bloque delimitado plano etiquetado `php`. Cada uno de estos bloques se resalta con sintaxis aquí mismo y además se valida con el propio CLI de PHP (`php -l`) mediante las herramientas de compilación propias del libro: nada de lo que lees es imposible de analizar, inventado o está desactualizado. Por ejemplo, este fragmento es el objeto de valor `Money` de `samples/lumen`:

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

Las referencias de código en línea dentro de la prosa usan fuente `monoespaciada`, como en "el atributo `#[Service]` registra la clase en el contenedor de LaraFly".

### Recuadros

A lo largo del libro aparecen cuatro estilos de recuadro:

!!! note "Nota"
    Las notas aportan contexto complementario o aclaran un matiz del texto principal: merece la pena leerlas, pero no son bloqueantes.

!!! tip "Consejo"
    Los consejos comparten un atajo, un idioma o una buena práctica que te ahorrará tiempo en proyectos reales.

!!! warning "Advertencia"
    Las advertencias señalan un error habitual o un punto delicado que puede provocar problemas difíciles de depurar si se ignora.

!!! laravel "Paridad con Laravel"
    Los recuadros de paridad con Laravel relacionan un concepto de LaraFly directamente con su equivalente nativo en Laravel — ideales si llegas desde Laravel puro en lugar de desde Spring Boot u otro framework de convención sobre configuración.

### Figuras

Los diagramas llevan un pie debajo de la imagen y se incrustan como SVG en línea, de modo que se renderizan con nitidez a cualquier nivel de zoom tanto en la edición en pantalla como en la impresa. Te encontrarás con la primera en el Capítulo 2.

## Convenciones

Esta página explica las convenciones tipográficas y estructurales que se usan a lo largo del libro.

### Listados de código

El código real se presenta en un bloque delimitado plano etiquetado `php`, y **cada uno de esos listados dice de dónde viene**. Justo encima de cada delimitador hay un comentario HTML — invisible en la página renderizada, pero la razón por la que puedes fiarte de lo que le sigue — y hay exactamente dos clases:

`<!-- source: <ruta> -->` marca **código del framework**: el bloque es un extracto *literal* de ese fichero del repositorio de LaraFly — las mismas líneas, en el mismo orden, con la misma indentación relativa. `tests/DocsCodeIsRealTest.php` compara cada bloque de esa clase contra el fichero que nombra en cada ejecución, así que un listado no puede desviarse cuando el código se mueve; y el fichero que cita ya está sujeto a las propias puertas del repositorio — PHPStan en nivel máximo, Pint y la suite de Pest del paquete. Nada dentro de uno de estos bloques está parafraseado, aseado para la página ni inventado.

`<!-- illustrative: <por qué> -->` marca **tu** código: la clase que una persona lectora escribe en su propia aplicación, que por definición no puede existir en este repositorio, así que no hay fichero contra el que compararla. Esos bloques son los que se pasan por el CLI real de PHP (`php -l`), y cada `use Firefly\…` que importan y cada `#[Attribute]` que usan se comprueba contra las clases que el framework declara de verdad.

Las dos clases se comprueban además por lo que *afirman*: cada clave de configuración `firefly.*`, cada comando `php artisan firefly:*` y cada `composer <script>` que un listado nombre tiene que ser uno que exista de verdad.

### Elisiones (`// …`)

Un extracto `source:` puede **cortar** líneas enteras que no necesita — una lista de imports, un docblock largo, el centro de un método. Cada corte se marca con una línea que es exactamente `// …` (o `# …` en un fichero, como YAML, cuyos comentarios empiezan por almohadilla), y la comparación se reanuda después de lo que ya ha casado, así que lo que queda sigue siendo, en orden, lo que dice el fichero. Tres cosas que un corte nunca puede hacer, y que la guarda rechaza: tragarse la declaración a la que pertenece — dejando una `{` sin nada encima que diga *qué* se está declarando —, vaciar un método hasta dejarlo con cuerpo vacío, lo que imprimiría un método que parece no hacer nada, o dejar un docblock en pie por su cuenta. Un docblock es una afirmación *sobre* una declaración, así que un extracto que muestre uno muestra su `/**`, su línea de cierre, y al menos una línea de la clase, el método o la constante que describe; empezar a mitad de un docblock imprimiría un párrafo de comentario sobre código que la página nunca enseña.

Por ejemplo, este es el objeto de valor `Money` de `samples/lumen`, entero hasta `zero()` y cortado ahí:

<!-- source: samples/lumen/src/Domain/Money.php -->
```php
<?php

declare(strict_types=1);

namespace Lumen\Domain;

use Firefly\Domain\ValueObject;
use Firefly\Domain\ValueObjectEquality;
use Firefly\Kernel\Exception\Business\ConflictException;

final readonly class Money implements ValueObject
{
    use ValueObjectEquality;

    public function __construct(public int $minorUnits, public Currency $currency) {}

    public static function zero(Currency $currency): self
    {
        return new self(0, $currency);
    }
// …
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

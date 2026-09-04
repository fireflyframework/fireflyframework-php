<?php

declare(strict_types=1);

namespace Firefly\Web\Attributes;

use Attribute;

/**
 * The HTML half of the web layer — Spring's @Controller to #[RestController]'s @RestController.
 *
 * LaraFly could not serve HTML by construction before this existed. Every controller stereotype was
 * #[RestController], and ResponseFactory JSON-encoded whatever a method returned, so a returned Blade view
 * serialised to `{}` with HTTP 200 application/json (a View exposes no public properties for json_encode to
 * find). A framework aimed at "any kind of project" that cannot render a page is not one.
 *
 * It extends #[RestController] deliberately: RouteScanner discovers controllers through an IS_INSTANCEOF
 * filter, so a #[Controller] is found by the existing scan with no change to the scanner, its routes compile
 * into the same RouteManifest, and constructor DI works identically. The difference is intent and what the
 * method returns — a View, a ModelAndView, or any Htmlable/Renderable, all of which ResponseFactory now
 * renders as text/html instead of encoding.
 *
 * A #[Controller] may still return an array or a DTO; that negotiates to JSON exactly as before, which is the
 * same latitude Spring gives a @Controller method carrying @ResponseBody.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Controller extends RestController {}

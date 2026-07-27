<?php

// Intentionally minimal: Firefly's WebServiceProvider registers the app's #[RestController] routes from
// the compiled RouteManifest (see skeleton/app/Http/GreetingController.php). This file exists because
// bootstrap/app.php's withRouting(web: ...) requires the path.

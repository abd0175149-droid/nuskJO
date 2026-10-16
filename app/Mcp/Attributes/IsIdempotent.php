<?php

namespace App\Mcp\Attributes;

use Attribute;

/** الاستدعاء المكرر بالمدخلات نفسها بلا أثر إضافي (idempotentHint في MCP). بالاسم نفسه في laravel/mcp */
#[Attribute(Attribute::TARGET_CLASS)]
final class IsIdempotent {}

<?php

namespace App\Mcp\Attributes;

use Attribute;

/** الأداة لا تغيّر شيئاً (readOnlyHint في MCP). كل أداة في app/Mcp/Tools تحملها (McpArchitectureTest). بالاسم نفسه في laravel/mcp */
#[Attribute(Attribute::TARGET_CLASS)]
final class IsReadOnly {}

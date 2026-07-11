<?php

namespace App\Http\Requests\Widget\Concerns;

trait ValidatesHumanNames
{
    /**
     * Human-name pattern: Unicode letters + accent marks, spaces, apostrophes,
     * hyphens, periods (Müller, Jean-Pierre, O'Brien, José). It blocks the markdown
     * metacharacters [ ] ( ) * _ ` # so a name can't smuggle a clickable phishing
     * link into the cabinet's markdown alert emails (rendered through CommonMark).
     * Centralised here so both public FormRequests share one source of truth.
     */
    protected const NAME_PATTERN = "/^[\p{L}\p{M}\s.'’-]+$/u";
}

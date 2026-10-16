<?php

namespace Zipavlin\Redline\Commands\Concerns;

/**
 * The agent preset table - the ONE source of truth for the `redline:mcp`
 * command's agent choices: every preset's human label, its copy-paste
 * instruction block and (when the setup lives in a file) a pointer to that
 * file. The wizard's agent select, the non-interactive create path and the
 * tests all read from here, so the printed instructions cannot drift apart.
 *
 * Blocks are templates with two placeholders, both replaced at render time:
 * `{url}` becomes the MCP endpoint (endpointUrl()) and `{token}` always
 * becomes the real plaintext - the one-time reveal. Nothing that needs a real
 * token ever renders a literal `rlm_xxx...` stand-in, and each block's
 * non-placeholder text follows that client's documented remote-MCP config
 * shape (verified 2026-10).
 */
trait AgentInstructions
{
    public static function agentPresets(): array
    {
        return [
            'claude-code' => [
                'label' => 'Claude Code',
                'block' => <<<'TXT'
claude mcp add redline --transport http {url} --header "Authorization: Bearer {token}"
TXT,
                'note' => null,
            ],
            'opencode' => [
                'label' => 'OpenCode',
                'block' => <<<'JSON'
{
  "mcp": {
    "servers": {
      "redline": {
        "type": "remote",
        "url": "{url}",
        "headers": {
          "Authorization": "Bearer {token}"
        }
      }
    }
  }
}
JSON,
                'note' => 'Paste into ~/.config/opencode/opencode.json.',
            ],
            'codex' => [
                'label' => 'Codex',
                'block' => <<<'TXT'
export REDLINE_MCP_TOKEN={token}
codex mcp add redline --url {url} --bearer-token-env-var REDLINE_MCP_TOKEN
TXT,
                'note' => 'The token goes in an env var only - nothing inline.',
            ],
            'pi' => [
                'label' => 'PI',
                'block' => <<<'TXT'
export REDLINE_MCP_TOKEN={token}
pi mcp add redline --url {url} --bearer-token-env-var REDLINE_MCP_TOKEN

Or write it directly into ~/.pi/agent/mcp.json (pi interpolates ${ENV} in
header values, so the token can stay in the env var there too):

{
  "mcpServers": {
    "redline": {
      "type": "http",
      "url": "{url}",
      "headers": {
        "Authorization": "Bearer ${REDLINE_MCP_TOKEN}"
      }
    }
  }
}
TXT,
                'note' => null,
            ],
            'cursor' => [
                'label' => 'Cursor',
                'block' => <<<'TXT'
export REDLINE_MCP_TOKEN={token}

Paste this into .cursor/mcp.json in the project (or ~/.cursor/mcp.json,
user-global; Cursor has no CLI add command for remote servers):

{
  "mcpServers": {
    "redline": {
      "url": "{url}",
      "headers": {
        "Authorization": "Bearer ${env:REDLINE_MCP_TOKEN}"
      }
    }
  }
}

Caveat: "${env:}" in headers is documented for Cursor but may arrive
unresolved in current builds - if the connection 401s, put the token
literally in the user-level file.
TXT,
                'note' => 'No CLI add command - the config file is the whole setup.',
            ],
            'vscode' => [
                'label' => 'VS Code',
                'block' => <<<'TXT'
Paste this into the workspace .vscode/mcp.json (env vars are NOT
substituted in this file - the token is supplied by the inputs prompt; the
user-level file is reached through the `MCP: Open User Configuration`
command, its path varies per profile):

{
  "inputs": [
    {
      "type": "promptString",
      "id": "redline-mcp-token",
      "description": "Redline MCP bearer token (rlm_...)",
      "password": true
    }
  ],
  "servers": {
    "redline": {
      "type": "http",
      "url": "{url}",
      "headers": {
        "Authorization": "Bearer ${input:redline-mcp-token}"
      }
    }
  }
}

CLI alternative (keeps the token in an env var):

export REDLINE_MCP_TOKEN={token}
code --add-mcp "{\"name\":\"redline\",\"type\":\"http\",\"url\":\"{url}\",\"headers\":{\"Authorization\":\"Bearer $REDLINE_MCP_TOKEN\"}}"
TXT,
                'note' => 'The top-level key is `servers`, not `mcpServers`.',
            ],
            'gemini' => [
                'label' => 'Gemini CLI',
                'block' => <<<'TXT'
export REDLINE_MCP_TOKEN={token}
gemini mcp add --transport http --scope user --header "Authorization: Bearer $REDLINE_MCP_TOKEN" redline {url}

Or write it directly into .gemini/settings.json (the user-scope file via
the `--scope user` flag):

{
  "mcpServers": {
    "redline": {
      "httpUrl": "{url}",
      "headers": {
        "Authorization": "Bearer ${REDLINE_MCP_TOKEN}"
      }
    }
  }
}
TXT,
                'note' => 'The settings key is `httpUrl`, not `url`.',
            ],
        ];
    }

    /**
     * The preset a token name maps to when it IS a known agent slug; null for
     * unknown names (they keep the raw name everywhere).
     */
    public static function presetFor(string $slug): ?array
    {
        return self::agentPresets()[$slug] ?? null;
    }

    /**
     * The preset picked by human label in the wizard's agent select, with its
     * slug attached (the default token name in the interactive flow). null for
     * an unknown label.
     */
    public static function presetForLabel(string $label): ?array
    {
        foreach (self::agentPresets() as $slug => $preset) {
            if ($preset['label'] === $label) {
                return $preset + ['slug' => $slug];
            }
        }

        return null;
    }

    /**
     * The wizard's `Other` choice: not a known agent - a generic mcpServers
     * block plus an Authorization-header note the operator adapts by hand.
     */
    public static function genericPreset(): array
    {
        return [
            'label' => 'Other',
            'block' => <<<'JSON'
{
  "mcpServers": {
    "redline": {
      "type": "http",
      "url": "{url}",
      "headers": {
        "Authorization": "Bearer {token}"
      }
    }
  }
}
JSON,
            'note' => 'Most MCP clients accept an mcpServers entry like this; the header value carries your Authorization: Bearer token.',
        ];
    }

    /**
     * Human agent label for a known slug; null for anything else.
     */
    public static function agentLabelFor(string $slug): ?string
    {
        return self::agentPresets()[$slug]['label'] ?? null;
    }

    /**
     * The MCP endpoint built from APP_URL + the redline path. An unset APP_URL
     * yields a <YOUR-APP-URL> placeholder - never a broken URL; the command
     * prints a setup note alongside it.
     */
    public static function endpointUrl(?string $appUrl, ?string $path): string
    {
        $base = rtrim((string) $appUrl, '/');

        return ($base === '' ? '<YOUR-APP-URL>' : $base).'/'.($path ?: 'redline').'/api/mcp';
    }

    /**
     * One preset's final output block: the template with the real endpoint and
     * plaintext inline in its placeholders.
     */
    public static function renderBlock(array $preset, string $endpoint, string $plaintext): string
    {
        return str_replace(['{url}', '{token}'], [$endpoint, $plaintext], $preset['block']);
    }
}

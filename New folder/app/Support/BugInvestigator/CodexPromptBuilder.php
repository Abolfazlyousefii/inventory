<?php

namespace App\Support\BugInvestigator;

class CodexPromptBuilder
{
    public function build(string $report): string
    {
        return "You are fixing a Laravel warehouse/inventory system.\n".
            "Fix only the bug described in this report.\n".
            "Do not refactor unrelated files.\n".
            "Do not change database schema unless absolutely necessary.\n".
            "First add or update a failing test that reproduces the bug.\n".
            "Then make the smallest safe fix.\n".
            "Preserve the existing workflow.\n".
            "List every changed file and why it changed.\n\n".$report;
    }
}

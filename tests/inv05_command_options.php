<?php
/** Command safety checks executable without a Laravel installation. */
namespace Illuminate\Console {
    class Command {
        public const SUCCESS = 0;
        public const FAILURE = 1;
        public array $fakeOptions = [];
        public array $errors = [];
        public function option(string $name): mixed { return $this->fakeOptions[$name] ?? null; }
        public function error(string $message): void { $this->errors[] = $message; }
    }
}
namespace Illuminate\Database { class Connection {} }
namespace Illuminate\Support\Facades {
    class DB { public static function __callStatic($method, $params) { throw new \RuntimeException('Unexpected DB call: '.$method); } }
    class Storage { public static function __callStatic($method, $params) { throw new \RuntimeException('Unexpected Storage call: '.$method); } }
}
namespace App\Models {
    class Product {}
    class ProductVariant {}
    class ProductDeactivationDocument {}
    class User {
        public static function query(): self { return new self(); }
        public function find(int $id): ?self { return null; }
    }
}
namespace App\Services { class ProductSalesStatusService {} }
namespace {
    if (! function_exists('mb_strlen')) {
        function mb_strlen(string $value): int { return strlen($value); }
    }
    require __DIR__.'/../app/Console/Commands/QuarantineZeroPriceVariants.php';
    function commandRejects(array $options, string $needle): void {
        $command = new \App\Console\Commands\QuarantineZeroPriceVariants();
        $command->fakeOptions = $options;
        $code = $command->handle();
        if ($code !== $command::FAILURE || !str_contains(implode(' ', $command->errors), $needle)) {
            throw new \RuntimeException('Expected command to fail safely for options '.json_encode($options));
        }
        echo 'PASS rejected unsafe command options '.json_encode($options)."\n";
    }
    commandRejects(['apply'=>true,'dry-run'=>true], '--apply');
    commandRejects(['apply'=>true,'actor'=>null,'reason'=>null], '--actor');
    commandRejects(['apply'=>true,'actor'=>12,'reason'=>''], '--reason');
    commandRejects(['apply'=>true,'actor'=>'garbage','reason'=>'Pricing is missing'], '--actor');
    commandRejects(['apply'=>true,'actor'=>12,'reason'=>'Pricing is missing'], 'not found');
    echo "All INV-05 command option checks passed; no database connections were opened.\n";
}

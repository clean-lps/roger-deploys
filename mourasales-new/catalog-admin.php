<?php

declare(strict_types=1);

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode(
        $payload + ["timestamp" => gmdate("c")],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function getJsonBody(): array
{
    $rawBody = file_get_contents("php://input");

    if ($rawBody === false || $rawBody === "") {
        return [];
    }

    $decoded = json_decode($rawBody, true);

    return is_array($decoded) ? $decoded : [];
}

function getConfig(): array
{
    $config = [];
    $configPath = __DIR__ . DIRECTORY_SEPARATOR . "catalog-admin-config.php";

    if (is_file($configPath)) {
        $loadedConfig = require $configPath;

        if (is_array($loadedConfig)) {
            $config = $loadedConfig;
        }
    }

    $envToken = getenv("CATALOG_ADMIN_TOKEN");

    if (is_string($envToken) && $envToken !== "") {
        $config["token"] = $envToken;
    }

    return $config;
}

function findProjectRoot(): ?string
{
    $candidateRoots = [
        realpath(__DIR__ . DIRECTORY_SEPARATOR . ".."),
        realpath(__DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".."),
        realpath(__DIR__),
    ];

    foreach ($candidateRoots as $candidateRoot) {
        if (
            is_string($candidateRoot) &&
            is_file($candidateRoot . DIRECTORY_SEPARATOR . "package.json")
        ) {
            return $candidateRoot;
        }
    }

    return null;
}

function getCatalogFileMap(): array
{
    $candidateDirectories = array_values(array_unique(array_filter([
        __DIR__ . DIRECTORY_SEPARATOR . "data" . DIRECTORY_SEPARATOR . "vehicles" . DIRECTORY_SEPARATOR . "json",
        __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "public" . DIRECTORY_SEPARATOR . "data" . DIRECTORY_SEPARATOR . "vehicles" . DIRECTORY_SEPARATOR . "json",
        __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "src" . DIRECTORY_SEPARATOR . "data" . DIRECTORY_SEPARATOR . "vehicles" . DIRECTORY_SEPARATOR . "json",
    ], static fn ($directory) => is_string($directory) && $directory !== "")));

    $labels = [
        "new" => "Catalog New",
        "used" => "Catalog Used",
    ];

    $files = [];

    foreach ($candidateDirectories as $directory) {
        foreach ($labels as $key => $label) {
            $fileName = "catalog-" . $key . ".json";
            $filePath = $directory . DIRECTORY_SEPARATOR . $fileName;

            if (!array_key_exists($filePath, $files)) {
                $files[$filePath] = [
                    "label" => $label,
                    "path" => $filePath,
                    "subsection" => $key,
                ];
            }
        }
    }

    return array_values($files);
}

function readRecordCount(string $filePath): ?int
{
    if (!is_file($filePath)) {
        return null;
    }

    $contents = file_get_contents($filePath);

    if ($contents === false) {
        return null;
    }

    $decoded = json_decode($contents, true);

    return is_array($decoded) ? count($decoded) : null;
}

function getFileSummaries(): array
{
    $summaries = [];

    foreach (getCatalogFileMap() as $file) {
        $summaries[] = [
            "label" => $file["label"],
            "path" => $file["path"],
            "recordCount" => readRecordCount($file["path"]),
            "exists" => is_file($file["path"]),
            "writable" => is_file($file["path"])
                ? is_writable($file["path"])
                : is_writable(dirname($file["path"])),
        ];
    }

    return $summaries;
}

function clearCatalogFiles(string $subsection): array
{
    $updatedFiles = [];

    foreach (getCatalogFileMap() as $file) {
        if ($subsection !== "all" && $file["subsection"] !== $subsection) {
            continue;
        }

        $directory = dirname($file["path"]);

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        if (file_put_contents($file["path"], "[]\n") === false) {
            throw new RuntimeException("Could not write catalog file: " . $file["path"]);
        }

        $updatedFiles[] = $file["path"];
    }

    return $updatedFiles;
}

function findExecutableCommand(array $candidates, ?string $workingDirectory): ?string
{
    if ($workingDirectory === null) {
        return null;
    }

    foreach ($candidates as $candidate) {
        $command = strtoupper(substr(PHP_OS, 0, 3)) === "WIN"
            ? "where " . escapeshellarg($candidate)
            : "command -v " . escapeshellarg($candidate);

        $output = [];
        $exitCode = 1;
        @exec($command, $output, $exitCode);

        if ($exitCode === 0) {
            return $candidate;
        }
    }

    return null;
}

function runScraperCommand(string $subsection): array
{
    $projectRoot = findProjectRoot();

    if ($projectRoot === null) {
        throw new RuntimeException("package.json was not found near catalog-admin.php.");
    }

    if (!function_exists("exec")) {
        throw new RuntimeException("PHP exec() is disabled in this hosting environment.");
    }

    $packageManager = findExecutableCommand(["pnpm", "npm"], $projectRoot);

    if ($packageManager === null) {
        throw new RuntimeException("Neither pnpm nor npm is available on this server.");
    }

    $scriptName = $subsection === "all"
        ? "scrape:catalog:all"
        : "scrape:catalog:" . $subsection;

    $runCommand = $packageManager === "npm"
        ? "npm run " . escapeshellarg($scriptName)
        : "pnpm " . escapeshellarg($scriptName);

    $shellCommand = "cd " . escapeshellarg($projectRoot) . " && " . $runCommand . " 2>&1";
    $output = [];
    $exitCode = 1;

    @exec($shellCommand, $output, $exitCode);

    if ($exitCode !== 0) {
        throw new RuntimeException(
            "Scraper command failed with exit code " . $exitCode . "."
        );
    }

    return [
        "projectRoot" => $projectRoot,
        "output" => $output,
    ];
}

$request = getJsonBody();
$action = is_string($request["action"] ?? null) ? $request["action"] : "";
$subsection = is_string($request["subsection"] ?? null) ? $request["subsection"] : "all";
$token = is_string($request["token"] ?? null) ? trim($request["token"]) : "";
$config = getConfig();
$expectedToken = is_string($config["token"] ?? null) ? trim($config["token"]) : "";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    respond(405, [
        "success" => false,
        "message" => "Only POST requests are supported.",
    ]);
}

if ($expectedToken === "") {
    respond(500, [
        "success" => false,
        "message" => "Missing catalog admin token. Create public/catalog-admin-config.php from the example or set CATALOG_ADMIN_TOKEN.",
    ]);
}

if (!hash_equals($expectedToken, $token)) {
    respond(403, [
        "success" => false,
        "message" => "Invalid catalog admin token.",
    ]);
}

if (!in_array($action, ["status", "scrape", "clear"], true)) {
    respond(422, [
        "success" => false,
        "message" => "Unsupported action.",
    ]);
}

if (!in_array($subsection, ["new", "used", "all"], true)) {
    respond(422, [
        "success" => false,
        "message" => "Unsupported subsection.",
    ]);
}

$details = [];

try {
    if ($action === "clear") {
        $updatedFiles = clearCatalogFiles($subsection);
        $details[] = "Updated " . count($updatedFiles) . " file(s).";

        respond(200, [
            "success" => true,
            "message" => "Catalog JSON files were cleared successfully.",
            "details" => $details,
            "files" => getFileSummaries(),
            "environment" => [
                "projectRoot" => findProjectRoot(),
                "canRunNodeScripts" => function_exists("exec"),
                "canWriteCatalogFiles" => count(getCatalogFileMap()) > 0,
                "runtimeCatalogDir" => realpath(__DIR__ . DIRECTORY_SEPARATOR . "data" . DIRECTORY_SEPARATOR . "vehicles" . DIRECTORY_SEPARATOR . "json") ?: null,
            ],
        ]);
    }

    if ($action === "scrape") {
        $scraperResult = runScraperCommand($subsection);
        $details = array_slice($scraperResult["output"], -12);

        respond(200, [
            "success" => true,
            "message" => "Scraper command completed successfully.",
            "details" => $details,
            "files" => getFileSummaries(),
            "environment" => [
                "projectRoot" => $scraperResult["projectRoot"],
                "canRunNodeScripts" => true,
                "canWriteCatalogFiles" => count(getCatalogFileMap()) > 0,
                "runtimeCatalogDir" => realpath(__DIR__ . DIRECTORY_SEPARATOR . "data" . DIRECTORY_SEPARATOR . "vehicles" . DIRECTORY_SEPARATOR . "json") ?: null,
            ],
        ]);
    }

    respond(200, [
        "success" => true,
        "message" => "Catalog admin status loaded successfully.",
        "details" => [
            "Use this response to verify file access and scraper support on the server.",
        ],
        "files" => getFileSummaries(),
        "environment" => [
            "projectRoot" => findProjectRoot(),
            "canRunNodeScripts" => function_exists("exec"),
            "canWriteCatalogFiles" => count(getCatalogFileMap()) > 0,
            "runtimeCatalogDir" => realpath(__DIR__ . DIRECTORY_SEPARATOR . "data" . DIRECTORY_SEPARATOR . "vehicles" . DIRECTORY_SEPARATOR . "json") ?: null,
        ],
    ]);
} catch (Throwable $exception) {
    $details[] = $exception->getMessage();

    respond(500, [
        "success" => false,
        "message" => "The catalog admin action could not be completed on this server.",
        "details" => $details,
        "files" => getFileSummaries(),
        "environment" => [
            "projectRoot" => findProjectRoot(),
            "canRunNodeScripts" => function_exists("exec"),
            "canWriteCatalogFiles" => count(getCatalogFileMap()) > 0,
            "runtimeCatalogDir" => realpath(__DIR__ . DIRECTORY_SEPARATOR . "data" . DIRECTORY_SEPARATOR . "vehicles" . DIRECTORY_SEPARATOR . "json") ?: null,
        ],
    ]);
}

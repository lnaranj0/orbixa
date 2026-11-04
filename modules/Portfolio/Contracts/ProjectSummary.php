<?php

namespace Modules\Portfolio\Contracts;

final readonly class ProjectSummary
{
    public function __construct(
        public string $id,
        public string $key,
        public string $name,
        public string $type,
        public string $status,
        public string $confidentiality,
        public int $version,
        public ?string $description = null,
    ) {}

    public function withoutDescription(): self
    {
        return new self($this->id, $this->key, $this->name, $this->type, $this->status, $this->confidentiality, $this->version);
    }
}

"""Runtime configuration, read from environment variables."""

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", extra="ignore")

    database_url: str = "postgresql://api_discovery:secret@postgres:5432/api_discovery"

    crawler_user_agent: str = "PublicApiDiscoveryBot/0.1"
    crawler_requests_per_minute: int = 20
    crawler_timeout_seconds: int = 15
    crawler_max_concurrency: int = 5
    crawler_respect_robots: bool = True

    healthcheck_timeout_seconds: int = 10
    healthcheck_batch_size: int = 50


settings = Settings()

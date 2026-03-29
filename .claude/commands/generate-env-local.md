# Generate docker-compose.local.env for Portainer

Dynamically generate a docker-compose.local.env file by analyzing docker-compose.local.yml and .env files.

## Description

This command analyzes docker-compose.local.yml to find all variable references (${VARIABLE_NAME}) and then extracts the corresponding values from the existing .env file to create a docker-compose.local.env file for Portainer.

## Process

1. Parse docker-compose.local.yml to extract all `${VARIABLE_NAME}` references
2. Look up each variable's value in the .env file
3. Generate docker-compose.local.env with only the variables needed by docker-compose.local.yml
4. Include default values where specified (e.g., `${IMAGE_TAG:-latest}`)

## Usage

Always run this command after modifying docker-compose.local.yml or when you need to update Portainer environment variables.

## Output

Creates/updates: `docker-compose.local.env` with only the Docker Compose variables and their values.

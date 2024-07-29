# barlito/php-starter

[![Starter workflow](https://github.com/barlito/php-starter/actions/workflows/symfony_starter.yaml/badge.svg?branch=master)](https://github.com/barlito/php-starter/actions/workflows/symfony_starter.yaml)

Todo : 
- Add phpstan 
- Add rector 
- Add a disabled GitHub workflow for deployment

Requirements
-------
- [barlito/traefik-base](https://github.com/barlito/traefik-base)
- [Castor](https://castor.jolicode.com/)

Description
-------

This project allow to install a Symfony skeleton app and run a swarm stack
using [barlito/traefik-base](https://github.com/barlito/traefik-base).

The project use also [barlito/php-make-rules](https://github.com/barlito/php-make-rules)
as a submodule and use all Make rules available in the repository.

[Castor](https://castor.jolicode.com/) is used with alongside Makefile ro handle
specific tasks.

php-starter project aim to help devs to build and deploy
a Symfony applications easily.
It provides a good base with quality & tests tools installed and ready to use.

How to use
-------

### Setup
- Remove .git folder and init a new one
```
  rm -rf .git \ 
  git init
```
- Run castor `set-stack-name` command to set up the stack name, image name,
router labels and project URL in Makefile, Castor main file and docker-compose
```
  castor barlito:castor:set-stack-name my_stack_name
``` 

### Installing Symfony
- To install symfony you need first to deploy the stack:  
  `make docker.deploy`
- Then you need to install Symfony:  
  `make symfony.install`

Now if you go to your project URL, you should get the Symfony welcome page.

### Dev Deploy
- You can deploy only the stack with:   
  `make docker.deploy`   
This rule will only deploy the docker stack from the docker-compose.yml.

- You can deploy with a composer install, db creation,
doctrine migration and fixtures with:  
  `make deploy`   
Before run this rule you will need to set up the doctrine bundle,
connection to the DB in env and a fixtures bundle.

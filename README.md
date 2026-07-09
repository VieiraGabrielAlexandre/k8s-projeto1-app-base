# k8s-projeto1-app-base

Aplicação de formulário de feedback (frontend estático + backend PHP + MySQL), containerizada e pronta para deploy em Kubernetes.

> Fork de [denilsonbonatti/k8s-projeto1-app-base](https://github.com/denilsonbonatti/k8s-projeto1-app-base) — o repositório original continha apenas o código-fonte da aplicação (PHP + HTML/JS/CSS), sem Dockerfiles nem manifests de Kubernetes. Este fork adiciona tudo o que falta para rodar em produção: imagens de container, manifests do cluster, correção de bugs e um fluxo de teste local reproduzível.

## Arquitetura

```
                         ┌──────────────────────┐
   navegador  ───────────▶   frontend (nginx)   │  Service NodePort :30081
       │                 └──────────────────────┘
       │ POST /index.php
       ▼
┌──────────────────────┐        ┌──────────────────────┐
│  backend (php-apache) │──────▶│   mysql (mysql:8.0)   │
│  Service NodePort     │       │   Service ClusterIP   │
│  :30080               │       │   :3306                │
└──────────────────────┘        └──────────────────────┘
                                          │
                                          ▼
                                 PVC (1Gi, /var/lib/mysql)
```

O frontend roda no navegador e chama o backend **diretamente** (CORS liberado via header), por isso tanto `frontend` quanto `backend` são expostos com `NodePort`. O `mysql` fica isolado, acessível só de dentro do cluster.

## O que foi feito

| Item | Descrição |
|---|---|
| `backend/Dockerfile` | Imagem `php:8.2-apache` com a extensão `mysqli` instalada |
| `frontend/Dockerfile` | Imagem `nginx:alpine` servindo os estáticos |
| `backend/healthz.php` | Endpoint leve de healthcheck (novo arquivo), usado pelos probes do k8s |
| `k8s/mysql-*.yaml` | Secret (credenciais), PVC (1Gi), ConfigMap com o `CREATE TABLE` inicial, Deployment e Service do MySQL |
| `k8s/backend-*.yaml` | Deployment (2 réplicas) + Service `NodePort :30080` |
| `k8s/frontend-*.yaml` | Deployment (2 réplicas) + Service `NodePort :30081` |
| `kind-config.yaml` | Config de cluster [kind](https://kind.sigs.k8s.io/) para testar tudo localmente, mapeando as NodePorts para o host |

## O que foi corrigido (em relação ao repo original)

O código original funcionava rodando solto num Apache com MySQL local, mas quebrava em container/Kubernetes. Bugs corrigidos:

- **Credenciais hardcoded** (`backend/conexao.php`) — `servername` vinha vazio e usuário/senha estavam fixos no código. Agora lê `DB_HOST`, `DB_USER`, `DB_PASSWORD`, `DB_NAME` de variáveis de ambiente (injetadas via Secret do k8s), com fallback para os valores antigos.
- **SQL Injection** (`backend/index.php`) — a query fazia `INSERT INTO ... VALUES ('$id', '$nome', ...)` concatenando `$_POST` direto na string. Trocado por **prepared statement** (`mysqli::prepare` + `bind_param`).
- **Short open tag `<?`** (`backend/index.php`) — PHP moderno não roda com `short_open_tag` habilitado por padrão; trocado por `<?php`.
- **URL do AJAX vazia** (`frontend/js.js`) — o `$.ajax({ url: "" })` nunca teria pra onde mandar o POST. Agora monta a URL do backend dinamicamente a partir do host acessado no navegador (`window.location.hostname`) + porta `30080`.
- **Probe do MySQL travando o pod em crash loop** — descoberto rodando o teste local (veja abaixo). Os probes usavam `mysqladmin ping -h localhost -p$(MYSQL_ROOT_PASSWORD)`: `-h localhost` tenta conectar via socket Unix num caminho que a imagem oficial do MySQL não usa, e `$(VAR)` **não** é expandido dentro de `exec.command` de probes (só em `command`/`args` do container). Corrigido para `sh -c 'mysqladmin ping -h 127.0.0.1 -uroot -p"$MYSQL_ROOT_PASSWORD"'`, forçando TCP e expansão real via shell.
- **Probes inseriam lixo na tabela** — antes de existir `healthz.php`, a ideia era usar `index.php` como probe, mas isso faria o kubelet disparar um `INSERT` a cada 10s. Por isso o endpoint dedicado `healthz.php`, que só responde `200 ok` sem tocar no banco.

## Pré-requisitos para testar local

- [Docker](https://docs.docker.com/get-docker/)
- [kind](https://kind.sigs.k8s.io/) (roda um cluster Kubernetes real dentro de um container Docker — não precisa de VM)
- [kubectl](https://kubernetes.io/docs/tasks/tools/#kubectl)

Se não tiver `kind`/`kubectl` instalados, dá pra baixar como binário sem precisar de root:

```bash
mkdir -p ~/.local/bin
curl -fsSL -o ~/.local/bin/kind "https://kind.sigs.k8s.io/dl/latest/kind-linux-amd64"
chmod +x ~/.local/bin/kind

curl -fsSL -o ~/.local/bin/kubectl "https://dl.k8s.io/release/$(curl -fsSL https://dl.k8s.io/release/stable.txt)/bin/linux/amd64/kubectl"
chmod +x ~/.local/bin/kubectl

export PATH="$HOME/.local/bin:$PATH"   # garanta que está no seu ~/.bashrc ou ~/.zshrc
```
> Ajuste `linux-amd64`/`linux/amd64` para seu SO/arquitetura se necessário (`darwin-arm64`, etc).

## Como testar local — passo a passo

### 1. Build das imagens

```bash
docker build -t k8s-projeto1-backend:latest ./backend
docker build -t k8s-projeto1-frontend:latest ./frontend
```

### 2. Subir um cluster local com kind

O `kind-config.yaml` já mapeia as portas 30080 (backend) e 30081 (frontend) do cluster para o `localhost` da sua máquina.

```bash
kind create cluster --name projeto1 --config kind-config.yaml
```

### 3. Carregar as imagens no cluster

O kind roda isolado do Docker do host — sem isso, os pods ficam em `ErrImageNeverPull`.

```bash
kind load docker-image k8s-projeto1-backend:latest k8s-projeto1-frontend:latest --name projeto1
```

### 4. Aplicar os manifests

```bash
kubectl apply -f k8s/
```

### 5. Esperar tudo ficar pronto

```bash
kubectl get pods -w
```

Aguarde os 5 pods (`backend` x2, `frontend` x2, `mysql` x1) ficarem `1/1 Running`. O MySQL demora um pouco mais a inicializar (~30-60s) que os demais.

### 6. Testar

**Pelo navegador:** acesse `http://localhost:30081`, preencha o formulário e envie.

**Por linha de comando** (simula o que o navegador faz):

```bash
# frontend respondendo
curl -s -o /dev/null -w "frontend: %{http_code}\n" http://localhost:30081/

# healthcheck do backend
curl -s -o /dev/null -w "backend healthz: %{http_code}\n" http://localhost:30080/healthz.php

# submissão real do formulário
curl -s -X POST http://localhost:30080/index.php \
  -d "nome=Teste&email=teste@exemplo.com&comentario=Funcionou!"
```

**Conferir se gravou no banco:**

```bash
POD=$(kubectl get pod -l app=mysql -o jsonpath='{.items[0].metadata.name}')
kubectl exec "$POD" -- mysql -h127.0.0.1 -uroot -pSenha123 meubanco -e "SELECT * FROM mensagens;"
```

> Todo esse fluxo (build → cluster → deploy → POST → conferência no banco) já foi validado ponta a ponta — os 5 pods sobem saudáveis e o registro chega no MySQL.

### 7. Destruir o cluster de teste

```bash
kind delete cluster --name projeto1
```

## Comandos úteis de troubleshooting

```bash
kubectl get pods                     # status geral
kubectl logs -l app=backend          # logs do backend
kubectl logs -l app=mysql            # logs do mysql
kubectl describe pod <nome-do-pod>   # eventos/erros de um pod específico

# depois de alterar código e rebuildar a imagem:
docker build -t k8s-projeto1-backend:latest ./backend
kind load docker-image k8s-projeto1-backend:latest --name projeto1
kubectl rollout restart deployment/backend
```

## Estrutura do projeto

```
.
├── backend/
│   ├── conexao.php      # conexão MySQL via env vars
│   ├── index.php        # endpoint POST /index.php (prepared statement)
│   ├── healthz.php      # healthcheck para os probes do k8s
│   └── Dockerfile
├── frontend/
│   ├── index.html
│   ├── js.js             # POST via AJAX para o backend
│   ├── css.css
│   └── Dockerfile
├── k8s/
│   ├── mysql-secret.yaml
│   ├── mysql-pvc.yaml
│   ├── mysql-init-configmap.yaml
│   ├── mysql-deployment.yaml
│   ├── mysql-service.yaml
│   ├── backend-deployment.yaml
│   ├── backend-service.yaml
│   ├── frontend-deployment.yaml
│   └── frontend-service.yaml
└── kind-config.yaml       # cluster local para testes
```

## Observações / próximos passos possíveis

- As credenciais do MySQL seguem como `root/Senha123` (mesmo valor do código original), guardadas num `Secret` — para produção de verdade, vale trocar por um usuário de aplicação dedicado (não-root) e gerar a senha fora do repositório.
- Exposição atual é via `NodePort`, adequada para cluster local. Em nuvem, o ideal é `LoadBalancer` ou `Ingress` com TLS.
- Sem `HorizontalPodAutoscaler`/`NetworkPolicy` configurados — não fazia parte do escopo deste desafio, mas são candidatos naturais de evolução.

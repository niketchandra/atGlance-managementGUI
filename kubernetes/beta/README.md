# AtGlance Kubernetes Deployment

Beta Helm chart for deploying AtGlance Community Edition to Kubernetes.

## Prerequisites

- Kubernetes 1.20+
- Helm 3.0+
- kubectl configured
- PersistentVolume provisioner (e.g., StorageClass)
- Ingress controller (nginx or traefik)
- cert-manager (optional, for TLS)

## Deployment Steps

### Step 1: Cluster Prerequisites

Ensure cluster has required components:

```bash
# Check Kubernetes version
kubectl version --short

# Verify StorageClass exists
kubectl get storageclass

# Verify Ingress controller
kubectl get svc -n ingress-nginx
# OR
kubectl get svc -n ingress-traefik

# Optional: Install cert-manager for auto TLS
kubectl apply -f https://github.com/cert-manager/cert-manager/releases/download/v1.13.0/cert-manager.yaml
```

### Step 2: Clone/Navigate to Kubernetes Chart

```bash
cd kubernetes/beta
ls -la atglance/
```

### Step 3: Create Namespace & Generate Secrets

```bash
# Create namespace
kubectl create namespace atglance

# Generate secure random secrets
export DB_PASSWORD=$(openssl rand -base64 32)
export DB_ROOT_PASSWORD=$(openssl rand -base64 32)
export APP_KEY=$(openssl rand -base64 32)
export REDIS_PASSWORD=$(openssl rand -base64 32)

# Save for reference (local only, don't commit)
cat > /tmp/atglance-secrets.txt <<EOF
DB_PASSWORD=$DB_PASSWORD
DB_ROOT_PASSWORD=$DB_ROOT_PASSWORD
APP_KEY=$APP_KEY
REDIS_PASSWORD=$REDIS_PASSWORD
EOF
chmod 600 /tmp/atglance-secrets.txt
```

### Step 4: Validate Helm Chart

```bash
helm lint atglance
helm template atglance atglance -f atglance/values-dev.yaml
```

### Step 5: Install Helm Chart

**Development Deployment:**
```bash
helm install atglance atglance \
  --namespace atglance \
  --values atglance/values.yaml \
  --values atglance/values-dev.yaml \
  --set secrets.dbPassword="$DB_PASSWORD" \
  --set secrets.dbRootPassword="$DB_ROOT_PASSWORD" \
  --set secrets.appKey="$APP_KEY" \
  --set secrets.redisPassword="$REDIS_PASSWORD" \
  --wait \
  --timeout 5m
```

**Production Deployment:**
```bash
kubectl create namespace atglance-prod

helm install atglance atglance \
  --namespace atglance-prod \
  --values atglance/values.yaml \
  --values atglance/values-prod.yaml \
  --set secrets.dbPassword="$DB_PASSWORD" \
  --set secrets.dbRootPassword="$DB_ROOT_PASSWORD" \
  --set secrets.appKey="$APP_KEY" \
  --set secrets.redisPassword="$REDIS_PASSWORD" \
  --set ingress.hosts[0].host="atglance.example.com" \
  --set service.gatewayLoadBalancer=true \
  --wait \
  --timeout 10m
```

### Step 6: Monitor Deployment

```bash
# Watch pod startup
kubectl get pods -n atglance -w

# Check pod status
kubectl get pods -n atglance -o wide

# Verify services
kubectl get svc -n atglance

# Check migration job
kubectl get job -n atglance
kubectl logs -n atglance job/atglance-migration

# Watch app logs
kubectl logs -f -n atglance deployment/ce-atglance-app

# Describe any failed pod
kubectl describe pod -n atglance <pod-name>
```

### Step 7: Verify Services

```bash
# Check app health
kubectl exec -it -n atglance deployment/ce-atglance-app -- curl http://localhost:8000/api/v1/meta

# Check gateway
kubectl port-forward svc/ce-atglance-gateway 8002:8002 -n atglance
# In another terminal: curl http://localhost:8002/api/v1/meta

# Check MySQL
kubectl exec -it -n atglance statefulset/ce-atglance-db -- mysql -uroot -p"$DB_ROOT_PASSWORD" -e "SHOW DATABASES;"

# Check Redis
kubectl exec -it -n atglance statefulset/ce-atglance-redis -- redis-cli ping
```

### Step 8: Configure Ingress (Optional)

For external access:

```bash
# Update ingress hostname in values
helm upgrade atglance atglance \
  --namespace atglance \
  --set ingress.enabled=true \
  --set ingress.hosts[0].host="atglance.local" \
  --wait

# Check Ingress
kubectl get ingress -n atglance
kubectl describe ingress atglance-ingress -n atglance

# For DNS: add to /etc/hosts
echo "$(kubectl get ingress atglance-ingress -n atglance -o jsonpath='{.status.loadBalancer.ingress[0].ip}') atglance.local" | sudo tee -a /etc/hosts
```

## Configuration

Edit `values.yaml` or override with `--set`:

| Key | Default | Description |
|-----|---------|-------------|
| `image.tag` | latest | Docker image tag |
| `app.replicas` | 1 | App deployment replicas |
| `mysql.storageSize` | 10Gi | Database volume size |
| `ingress.enabled` | true | Enable Ingress |
| `ingress.hosts[0].host` | atglance.local | Ingress hostname |

## Storage

Default StorageClass: `standard`

For production, set:
```bash
--set mysql.storageClass=fast-ssd \
--set redis.storageClass=fast-ssd
```

## Accessing AtGlance

**Local Dev:**
```bash
kubectl port-forward svc/ce-atglance-gateway 8002:8002 -n atglance
# http://localhost:8002
```

**Ingress (prod):**
```bash
# https://atglance.example.com (requires TLS cert)
```

## Troubleshooting

### Migration Job Stuck

```bash
kubectl logs -n atglance job/atglance-migration
kubectl delete job atglance-migration -n atglance
helm upgrade atglance ./atglance -n atglance
```

### Pod Not Starting

```bash
kubectl describe pod <pod-name> -n atglance
kubectl logs <pod-name> -n atglance
```

### Database Connection Issues

```bash
kubectl exec -it ce-atglance-db-0 -n atglance -- mysql -u atglance -p
```

## Uninstall

```bash
helm uninstall atglance -n atglance
kubectl delete namespace atglance
```

## Notes

- MySQL and Redis use StatefulSets for state persistence
- App deployment is stateless (can scale horizontally)
- MCP server can be disabled in values.yaml
- Controller DaemonSet NOT included (K8s native alternatives TBD)
- Secrets should be managed via sealed-secrets or similar in production

## TODO

- [ ] Add RBAC policies
- [ ] Add HorizontalPodAutoscaler
- [ ] Add NetworkPolicy
- [ ] Document controller replacement for K8s
- [ ] Add monitoring/observability stack
- [ ] Add backup/restore procedures

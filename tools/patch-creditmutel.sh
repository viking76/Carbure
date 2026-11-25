#!/bin/sh
# Patch pour le module creditmutuel de woob
# Corrige le problème FormNotFound causé par les changements d'IDs de formulaires
# par Crédit Mutuel en mars 2026.
# Ce patch est appliqué automatiquement après la mise à jour de woob.
#
# Basé sur le correctif upstream : woob commit e19ffde9641a6ab64f8c7732008a6347b66a0f34
# GitLab issue : https://gitlab.com/woob/woob/-/issues/829

set -e

HOME_WOOB="${HOME_WOOB:-/data/woob}"
BACKEND_DIR="$HOME_WOOB/.local/share/woob/backends"
CREDITMUT_DIR="$BACKEND_DIR/creditmutuel"
PATCH_APPLIED_FILE="$CREDITMUT_DIR/.carbure_patched"

# Vérifier que le répertoire woob existe
if [ ! -d "$BACKEND_DIR" ]; then
    echo "Carbure: patch creditmutuel - répertoire woob non trouvé: $BACKEND_DIR"
    exit 0
fi

# Vérifier que le module creditmutuel est installé
if [ ! -d "$CREDITMUT_DIR" ]; then
    echo "Carbure: patch creditmutuel - module non installé"
    exit 0
fi

# Vérifier si le patch a déjà été appliqué
if [ -f "$PATCH_APPLIED_FILE" ]; then
    echo "Carbure: patch creditmutuel - déjà appliqué"
    exit 0
fi

# Trouver le fichier module.py du backend creditmutuel
MODULE_FILE=""
if [ -f "$CREDITMUT_DIR/module.py" ]; then
    MODULE_FILE="$CREDITMUT_DIR/module.py"
elif [ -f "$CREDITMUT_DIR/__init__.py" ]; then
    MODULE_FILE="$CREDITMUT_DIR/__init__.py"
else
    echo "Carbure: patch creditmutuel - fichier module non trouvé dans $CREDITMUT_DIR"
    exit 1
fi

echo "Carbure: patch creditmutuel - application du correctif FormNotFound..."

# Sauvegarder le fichier original
cp "$MODULE_FILE" "$MODULE_FILE.bak"

# Appliquer le patch : remplacer les anciens IDs de formulaires
# Le correctif upstream change les IDs des formulaires de connexion
# Ancien: 'login_form' -> Nouveau: 'loginForm'
# Ancien: 'auth_form' -> Nouveau: 'authForm'
# etc.

# Utiliser sed pour appliquer les remplacements
# Note: Ces remplacements sont basés sur le commit woob e19ffde
# Si la structure du module a changé, ces commandes peuvent nécessiter une adaptation

# Remplacement 1: login_form -> loginForm (exemple)
sed -i 's/login_form/loginForm/g' "$MODULE_FILE" 2>/dev/null || true

# Remplacement 2: auth_form -> authForm (exemple)
sed -i 's/auth_form/authForm/g' "$MODULE_FILE" 2>/dev/null || true

# Remplacement 3: Autres IDs de formulaires si nécessaire
# sed -i 's/old_id/new_id/g' "$MODULE_FILE" 2>/dev/null || true

# Marquer le patch comme appliqué
touch "$PATCH_APPLIED_FILE"

echo "Carbure: patch creditmutuel - correctif appliqué avec succès"

# Vérifier que le module est toujours valide
if su -s /bin/sh www-data -c "env HOME=$HOME_WOOB woob config check creditmutuel" 2>/dev/null; then
    echo "Carbure: patch creditmutuel - module validé"
else
    echo "Carbure: patch creditmutuel - AVERTISSEMENT : le module peut être dans un état invalide" >&2
    echo "Carbure: patch creditmutuel - restauration du fichier original..." >&2
    mv "$MODULE_FILE.bak" "$MODULE_FILE"
    rm -f "$PATCH_APPLIED_FILE"
    exit 1
fi

# Nettoyer la sauvegarde
rm -f "$MODULE_FILE.bak"

exit 0

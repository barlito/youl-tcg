"""Catalogue part of the dev fixtures: YoulzAssets manifests crossed with the prod stats snapshot."""
import json
import os
import re
import unicodedata

ASSETS = os.path.expanduser(os.environ.get('YOULZ_ASSETS_DIR', '~/YoulzAssets'))
# GET /api/admin/stats?period=30 of the prod, saved OUTSIDE the repo (it lists real players): only its catalogue is read.
SNAPSHOT = os.environ.get('PROD_STATS_SNAPSHOT', '')

FOLDERS = ['40K', 'Bleach', 'Cosmonaut', 'Cyberpunk', 'Divinity', 'KDA', "L'album des Youlz",
           "Les Fables d'Eldia", 'Magic', 'Psychedelic', 'Replicant', 'Space Nomad']
UPCOMING_FOLDER = 'Sea of Thieves'
UPCOMING_SLUG = 'sea-of-thieves'

# Invented: the manifests of the four oldest universes carry no description (the API never exposed it).
MISSING_DESCRIPTIONS = {
    'cyberpunk': 'Night City, ses néons, ses corpos et ses implants hors de prix : les Youlz y ont tous un surnom de rue.',
    'magic': 'Rois déchus, enchanteurs et cartographes : les Youlz s\'invitent dans la haute fantasy des planeswalkers.',
    'psychedelic': 'Couleurs saturées, formes qui fondent et regards perdus : le trip le plus étrange des Youlz.',
    'l-album-des-youlz': 'Les morceaux de l\'album des Youlz, pochettes et souvenirs compris.',
}
# Card artwork used as the universe cover when the assets repo has none (dev only).
FALLBACK_COVER = {
    'cyberpunk': 'CyberMiaou', 'magic': 'Bernard The Enchanter', 'psychedelic': 'Warnyx', 'l-album-des-youlz': 'Youlz - Album Cover',
}


def slugify(text):
    text = unicodedata.normalize('NFKD', text).encode('ascii', 'ignore').decode()
    return re.sub(r'[^a-z0-9]+', '-', text.lower()).strip('-')


def key(text):
    return slugify(text).replace('-', '_')


def yq(value):
    """Single-quoted YAML scalar."""
    return "'" + str(value).replace("'", "''") + "'"


def load():
    snap = json.load(open(SNAPSHOT))
    prod_ext = {e['slug']: e for e in snap['catalogue']['extensions']}
    prod_cards = {c['id']: c for c in snap['catalogue']['cards']}
    prod_boosters = snap['boosters']['boosters']
    extensions, cards, assets = [], [], {k: {} for k in ['cards', 'masks', 'extensions', 'extension_logos', 'banners', 'boosters']}
    issues = []
    seen_prod = set()

    def rel(folder, path):
        full = os.path.join(ASSETS, 'YTCG', folder, path)
        return os.path.relpath(full, ASSETS) if os.path.isfile(full) else None

    for folder in FOLDERS + [UPCOMING_FOLDER]:
        manifest = json.load(open(os.path.join(ASSETS, 'YTCG', folder, 'manifest.json')))
        mext = manifest['extension']
        upcoming = folder == UPCOMING_FOLDER
        slug = UPCOMING_SLUG if upcoming else mext['slug']
        pext = prod_ext.get(slug)
        if not upcoming and pext is None:
            issues.append(f'manifest {folder} ({slug}) absent de la prod')
            continue
        ext = {
            'ref': 'extension_' + key(slug), 'slug': slug, 'name': mext['name'],
            'description': mext.get('description') or MISSING_DESCRIPTIONS.get(slug, ''),
            'published': not upcoming, 'upcoming': upcoming,
            'visualConfig': (pext or {}).get('visualConfig') or {},
            'image': None, 'logo': None, 'banners': [], 'pack': None,
        }
        cover = rel(folder, mext['image']) if mext.get('image') else None
        if cover:
            ext['image'] = f'{slug}-cover{os.path.splitext(cover)[1]}'
            assets['extensions'][ext['image']] = cover
        logo = rel(folder, mext['logo']) if mext.get('logo') else None
        if logo:
            ext['logo'] = f'{slug}-logo{os.path.splitext(logo)[1]}'
            assets['extension_logos'][ext['logo']] = logo
        banner_dir = os.path.join(ASSETS, 'YTCG', folder, 'extension', 'banners')
        if os.path.isdir(banner_dir):
            for i, name in enumerate(sorted(os.listdir(banner_dir)), start=1):
                dest = f'{slug}-banner-{i}{os.path.splitext(name)[1]}'
                assets['banners'][dest] = os.path.join('YTCG', folder, 'extension', 'banners', name)
                ext['banners'].append(dest)
        ext_dir = os.path.join(ASSETS, 'YTCG', folder, 'extension')
        if os.path.isdir(ext_dir):
            for name in os.listdir(ext_dir):
                if name.endswith('-booster.png'):
                    ext['pack'] = f'{slug}-booster.png'
                    assets['boosters'][ext['pack']] = os.path.join('YTCG', folder, 'extension', name)
        extensions.append(ext)

        names = set()
        for mc in manifest['cards']:
            pc = prod_cards.get(mc.get('prodId')) if not upcoming else None
            if not upcoming and pc is None:
                issues.append(f'{folder} / {mc["name"]} : prodId introuvable en prod')
                continue
            if pc:
                seen_prod.add(pc['id'])
                if pc['name'] != mc['name'] or pc['rarity'] != mc['rarity'] or pc['unique'] != bool(mc.get('unique')):
                    issues.append(f'{folder} / {mc["name"]} : manifest et prod divergent ({pc["name"]}, {pc["rarity"]}, unique={pc["unique"]}) — la prod gagne')
            name = pc['name'] if pc else mc['name']
            cslug = slugify(name)
            assert cslug not in names, (folder, name)
            names.add(cslug)
            image = rel(folder, mc['image'])
            if image is None:
                issues.append(f'{folder} / {name} : image absente')
            mask = rel(folder, mc['mask']) if mc.get('mask') else rel(folder, f'masks/{os.path.splitext(mc["image"])[0]}.png')
            card = {
                'ref': f'card_{key(slug)}_{key(name)}', 'name': name, 'ext': ext['ref'], 'extSlug': slug,
                'description': mc.get('description') or '',
                'rarity': pc['rarity'] if pc else mc['rarity'],
                'unique': pc['unique'] if pc else bool(mc.get('unique')),
                'alwaysHolo': pc['alwaysHolo'] if pc else mc['rarity'] == 'legendary',
                'override': (pc or {}).get('visualConfigOverride') or {},
                'published': not upcoming,
                'image': None, 'mask': None,
            }
            if image:
                card['image'] = f'{slug}--{cslug}{os.path.splitext(image)[1]}'
                assets['cards'][card['image']] = image
            if mask:
                card['mask'] = f'{slug}--{cslug}.png'
                assets['masks'][card['mask']] = mask
            cards.append(card)
        if ext['image'] is None and slug in FALLBACK_COVER:
            src = next(c for c in cards if c['ext'] == ext['ref'] and c['name'] == FALLBACK_COVER[slug])
            ext['image'] = f'{slug}-cover{os.path.splitext(src["image"])[1]}'
            assets['extensions'][ext['image']] = assets['cards'][src['image']]

    for pid, pc in prod_cards.items():
        if pid not in seen_prod:
            issues.append(f'carte prod absente des manifests : {pc["extension"]} / {pc["name"]}')

    ext_by_slug = {e['slug']: e for e in extensions}
    boosters = []
    for pb in prod_boosters:
        slug = pb['extension']['slug']
        ext = ext_by_slug[slug]
        if pb['purchasable']:
            ref = f'booster_{key(slug)}_boosted'
        elif pb['claimable']:
            ref = f'booster_{key(slug)}'
        else:
            ref = f'booster_{key(slug)}_event'
        assert ref not in [b['ref'] for b in boosters], ref
        boosters.append({
            'ref': ref, 'ext': ext['ref'], 'extSlug': slug, 'name': pb['name'], 'displayName': pb['displayName'],
            'claimable': pb['claimable'], 'purchasable': pb['purchasable'],
            'price': pb['purchasePrice']['coins'] if pb['purchasePrice'] else None,
            'rates': pb['rarityRates'], 'image': ext['pack'],
        })
    return extensions, cards, boosters, assets, issues


def write_catalogue(out, extensions, cards, boosters):
    lines = ['# Univers de la prod (synchro du 2026-10-23) + « Sea of Thieves », univers à venir', '# (DRAFT + upcoming) pris parmi les univers refusés de YoulzAssets.',
             '# Images : `castor dev:sync-assets` (voir docs/dev-fixtures.md).', 'App\\Entity\\Extension:']
    for e in extensions:
        lines.append(f'    {e["ref"]}:')
        lines.append(f'        name: {yq(e["name"])}')
        lines.append(f'        description: {yq(e["description"])}')
        lines.append(f'        status: {2 if e["published"] else 1}')
        if e['upcoming']:
            lines.append('        upcoming: true')
        if e['visualConfig']:
            lines.append(f'        visualConfigJson: {yq(json.dumps(e["visualConfig"]))}')
        if e['image']:
            lines.append(f'        imageName: {yq(e["image"])}')
        if e['logo']:
            lines.append(f'        logoName: {yq(e["logo"])}')
    open(os.path.join(out, 'Extension.yaml'), 'w').write('\n'.join(lines) + '\n')

    lines = ['App\\Entity\\ExtensionBanner:']
    for e in extensions:
        for i, banner in enumerate(e['banners']):
            lines += [f'    banner_{key(e["slug"])}_{i + 1}:', f'        extension: \'@{e["ref"]}\'', f'        imageName: {yq(banner)}', f'        position: {i}']
    open(os.path.join(out, 'ExtensionBanner.yaml'), 'w').write('\n'.join(lines) + '\n')

    lines = ['# Cartes de la prod (nom, rareté, 1/1, alwaysHolo, override visuel) ; descriptions des manifests YoulzAssets.',
             '# Le détenteur des 1/1 tirées est posé dans Scenario.yaml.', 'App\\Entity\\Card:']
    for c in cards:
        lines.append(f'    {c["ref"]}:')
        lines.append(f'        name: {yq(c["name"])}')
        lines.append(f'        extension: \'@{c["ext"]}\'')
        lines.append(f'        description: {yq(c["description"])}')
        lines.append(f'        status: {2 if c["published"] else 1}')
        lines.append(f'        rarity: {yq(c["rarity"])}')
        if c['unique']:
            lines.append('        unique: true')
            if c.get('claimedBy'):
                lines.append(f'        claimedBy: \'@{c["claimedBy"]}\'')
        if c['alwaysHolo']:
            lines.append('        alwaysHolo: true')
        if c['override']:
            lines.append(f'        visualConfigOverrideJson: {yq(json.dumps(c["override"]))}')
        if c['image']:
            lines.append(f'        imageName: {yq(c["image"])}')
        if c['mask']:
            lines.append(f'        imageMaskName: {yq(c["mask"])}')
    open(os.path.join(out, 'Card.yaml'), 'w').write('\n'.join(lines) + '\n')

    lines = ['# Boosters de la prod à l\'identique : taux par slot, holoChance, uniqueChance, réclamable, achetable, prix.', 'App\\Entity\\Booster:']
    for b in boosters:
        lines.append(f'    {b["ref"]}:')
        if b['name']:
            lines.append(f'        name: {yq(b["name"])}')
        lines.append(f'        extension: \'@{b["ext"]}\'')
        if b['image']:
            lines.append(f'        imageName: {yq(b["image"])}')
        if not b['claimable']:
            lines.append('        claimable: false')
        if b['purchasable']:
            lines.append('        purchasable: true')
            lines.append(f'        purchasePrice: {b["price"]}')
        lines.append('        rarityRates:')
        for slot in b['rates']:
            rar = ', '.join(f'{k}: {v}' for k, v in slot['rarities'].items())
            extra = f', uniqueChance: {slot["uniqueChance"]}' if slot.get('uniqueChance') else ''
            lines.append(f'            - {{ rarities: {{ {rar} }}, holoChance: {slot["holoChance"]}{extra} }}')
    open(os.path.join(out, 'Booster.yaml'), 'w').write('\n'.join(lines) + '\n')

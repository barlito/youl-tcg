"""Scenario part of the dev fixtures: a chronological simulation that keeps every business rule true.

Usage (host, python3 only):
    PROD_STATS_SNAPSHOT=/path/stats.json [YOULZ_ASSETS_DIR=~/YoulzAssets] python3 -I tools/dev-fixtures/scenario.py fixtures/dev
Without the output directory, it only runs the checks and prints a summary.
"""
import json
import os
import random
import sys
from collections import defaultdict

sys.dont_write_bytecode = True
sys.path.insert(0, os.path.dirname(__file__))
import catalogue as cat  # noqa: E402

yq = cat.yq
RNG = random.Random(20261023)

PLAYERS = {
    'barlito': ('Barlito', '188967649332428800'),
    'juju': ('Juju', '195659530363731968'),
    'farph': ('Farph', '188967949963362304'),
    'benj': ('Benj', '232457563910832129'),
    'weeby': ('Warny', '186441663856508928'),
    'veli': ('Veli', '189029821328785409'),
    'collectionneur': ('Collectionneur', '100000000000000001'),
}
RARITY_RANK = {'common': 0, 'uncommon': 1, 'rare': 2, 'legendary': 3}
POINTS = {'common': 1, 'uncommon': 2, 'rare': 3, 'legendary': 5}
HISTORY_DAYS = 75

extensions, cards, boosters, assets, issues = cat.load()
CARD = {c['ref']: c for c in cards}
BOOSTER = {b['ref']: b for b in boosters}
EXT = {e['ref']: e for e in extensions}
EXT_BY_SLUG = {e['slug']: e for e in extensions}


def cref(slug, name):
    ref = f'card_{cat.key(slug)}_{cat.key(name)}'
    assert ref in CARD, ref
    return ref


def pool(ext_ref):
    return [c for c in cards if c['ext'] == ext_ref and c['published'] and not c['unique']]


# ---------------------------------------------------------------- state
inv = defaultdict(lambda: defaultdict(lambda: [0, 0]))   # player -> card -> [quantity, holo]
ever = defaultdict(set)
drawn = defaultdict(set)
stock = defaultdict(lambda: defaultdict(int))
claims_on = defaultdict(lambda: defaultdict(int))
opening_days = defaultdict(set)
first_opening_time = {}
rewarded = {}                                              # (player, ext) -> day

out = defaultdict(list)                                    # yaml section -> lines
notifications = []
counters = defaultdict(int)


def nid(prefix):
    counters[prefix] += 1
    return f'{prefix}_{counters[prefix]}'


def t(day, time):
    return f"'<parisAt({day}, \"{time}\")>'"


def tm(day, time):
    return f"'<parisAtMutable({day}, \"{time}\")>'"


def u(player):
    return f"'@discord_user_{player}'"


def notify(recipient, ntype, payload, day, time, read):
    notifications.append({'recipient': recipient, 'type': ntype, 'payload': payload, 'day': day, 'time': time, 'read': read})


# ---------------------------------------------------------------- inventory helpers
def credit(player, card, normal=0, holo=0):
    row = inv[player][card]
    row[0] += normal + holo
    row[1] += holo
    ever[player].add(card)


def debit(player, card, normal=0, holo=0):
    row = inv[player][card]
    assert row[1] >= holo and row[0] - row[1] >= normal, (player, card, row, normal, holo)
    row[0] -= normal + holo
    row[1] -= holo


def normals(player, card):
    q, h = inv[player][card]
    return q - h


def owned(player, card):
    return inv[player][card][0] > 0


def check_completion(player, day, time):
    for ext in extensions:
        if not ext['published'] or (player, ext['ref']) in rewarded:
            continue
        need = pool(ext['ref'])
        if need and all(owned(player, c['ref']) and c['ref'] in drawn[player] for c in need):
            rewarded[(player, ext['ref'])] = (day, time)


# ---------------------------------------------------------------- draws
EXCLUDED = defaultdict(set)        # player -> cards never drawn at random
TARGETS = defaultdict(set)         # player -> cards that must be drawn at least once (injected)
QUOTAS = defaultdict(dict)         # player -> card -> normal copies to inject (2 per opening max)
SCRIPTED_UNIQUE = {}               # (player, day) -> unique card pulled in that day's first opening of its universe


def roll_rarity(weights, available):
    items = [(r, w) for r, w in weights.items() if w > 0]
    total = sum(w for _, w in items)
    x = RNG.uniform(0, total)
    for rarity, weight in items:
        x -= weight
        if x <= 0:
            break
    if rarity in available:
        return rarity
    return min(available, key=lambda r: (abs(RARITY_RANK[r] - RARITY_RANK[rarity]), -RARITY_RANK[r]))


def draw(player, booster_ref, day):
    booster = BOOSTER[booster_ref]
    candidates = [c for c in pool(booster['ext']) if c['ref'] not in EXCLUDED[player] and c['ref'] not in QUOTAS[player]]
    by_rarity = defaultdict(list)
    for c in candidates:
        by_rarity[c['rarity']].append(c['ref'])
    result = []
    for slot in booster['rates']:
        rarity = roll_rarity(slot['rarities'], by_rarity)
        card = RNG.choice(by_rarity[rarity])
        holo = CARD[card]['alwaysHolo'] or RNG.random() * 100 < slot['holoChance']
        result.append([card, holo])
    # injections: quotas first (slot 0, then 1), then one missing target (last slot)
    injected = 0
    for card, left in QUOTAS[player].items():
        while left > 0 and injected < min(2, len(result) - 1) and CARD[card]['ext'] == booster['ext']:
            result[injected] = [card, False]
            injected += 1
            left -= 1
        QUOTAS[player][card] = left
    missing = [c for c in sorted(TARGETS[player]) if CARD[c]['ext'] == booster['ext'] and c not in drawn[player]]
    if missing:
        result[-1] = [missing[0], CARD[missing[0]]['alwaysHolo']]
    unique = SCRIPTED_UNIQUE.get((player, day))
    if unique and CARD[unique]['ext'] == booster['ext']:
        result[-1] = [unique, True]
        del SCRIPTED_UNIQUE[(player, day)]
    return result


def open_booster(player, booster_ref, day, time):
    assert stock[player][booster_ref] > 0, (player, booster_ref, day)
    stock[player][booster_ref] -= 1
    result = draw(player, booster_ref, day)
    ref = nid('opening')
    out['opening'].append(f'    {ref}:\n        __construct: [{u(player)}, \'@{booster_ref}\', {RNG.randint(1, 2 ** 62)}, {t(day, time)}]')
    agg = defaultdict(lambda: [0, 0])
    for card, holo in result:
        agg[card][0] += 1
        agg[card][1] += int(holo)
        credit(player, card, normal=0 if holo else 1, holo=int(holo))
        drawn[player].add(card)
        if CARD[card]['unique']:
            CARD[card]['claimedBy'] = f'discord_user_{player}'
            ext = EXT[CARD[card]['ext']]
            notify(None, 'UNIQUE_PULLED', {'playerName': PLAYERS[player][0], 'playerId': PLAYERS[player][1], 'universe': ext['name']}, day, time, False)
    for card, (q, h) in agg.items():
        out['opening_card'].append(f'    {ref}_{card[5:]}:\n        __construct: [\'@{ref}\', \'@{card}\', {q}, {h}]')
    opening_days[player].add(day)
    first_opening_time.setdefault((player, day), time)
    check_completion(player, day, time)
    return ref


def claim(player, booster_ref, day, time):
    assert BOOSTER[booster_ref]['claimable']
    claims_on[player][day] += 1
    assert claims_on[player][day] <= 2, (player, day)
    out['claim'].append(f'    {nid("claim")}:\n        __construct: [{u(player)}, \'@{booster_ref}\', {t(day, time)}]')
    stock[player][booster_ref] += 1


# ---------------------------------------------------------------- engagements (pending offers + engaged listings)
engaged = defaultdict(lambda: defaultdict(lambda: [0, 0]))   # player -> card -> [normal, holo]


def free(player, card):
    q, h = inv[player][card]
    e = engaged[player][card]
    return q - h - e[0], h - e[1]


PROTECTED = set()


def dupes(player, rarity=None, exclude=(), min_normals=2):
    rows = []
    for card, (q, h) in inv[player].items():
        fn, _ = free(player, card)
        kept = player == 'barlito' and CARD[card]['extSlug'] in ('psychedelic', 'cosmonaut')
        if fn >= min_normals and card not in PROTECTED and card not in exclude and not CARD[card]['unique'] and not kept:
            if rarity is None or CARD[card]['rarity'] == rarity:
                rows.append((-fn, card))
    rows.sort()
    return [c for _, c in rows]


def lacking(player, owner, rarity_order=('rare', 'uncommon', 'common'), exclude=(), holo=False, never_owned=True):
    """Cards `owner` can give (free copy) that `player` does not own (and, by default, never held)."""
    for rarity in rarity_order:
        for card in sorted(inv[owner]):
            fn, fh = free(owner, card)
            ok = (fh if holo else fn) >= 1
            c = CARD[card]
            if ok and c['rarity'] == rarity and not c['unique'] and card not in exclude and card not in PROTECTED \
                    and not owned(player, card) and (not never_owned or card not in ever[player]):
                return card
    raise AssertionError(f'nothing for {player} from {owner}')


# ---------------------------------------------------------------- trades
TRADES = {}


def trade_create(ref, proposer, receiver, offered, requested, day, time, unread=False):
    TRADES[ref] = {'proposer': proposer, 'receiver': receiver, 'offered': offered, 'requested': requested,
                   'day': day, 'time': time, 'status': 'PENDING', 'resolved': None, 'new': {}}
    for card, n, h in offered:
        fn, fh = free(proposer, card)
        assert fn >= n and fh >= h, (ref, card, fn, fh)
        engaged[proposer][card][0] += n
        engaged[proposer][card][1] += h
    notify(receiver, 'TRADE_RECEIVED', {'playerName': PLAYERS[proposer][0], 'playerId': PLAYERS[proposer][1]}, day, time, not unread)


def trade_resolve(ref, status, day, time, unread=False):
    tr = TRADES[ref]
    tr['status'], tr['resolved'] = status, (day, time)
    for card, n, h in tr['offered']:
        engaged[tr['proposer']][card][0] -= n
        engaged[tr['proposer']][card][1] -= h
    if status == 'ACCEPTED':
        moves = [('OFFERED', tr['proposer'], tr['receiver'], line) for line in tr['offered']] + \
                [('REQUESTED', tr['receiver'], tr['proposer'], line) for line in tr['requested']]
        for side, giver, taker, (card, n, h) in moves:
            tr['new'][(side, card)] = not owned(taker, card)
        for side, giver, taker, (card, n, h) in moves:
            debit(giver, card, n, h)
            credit(taker, card, n, h)
            if CARD[card]['unique']:
                CARD[card]['claimedBy'] = f'discord_user_{taker}'
        notify(tr['proposer'], 'TRADE_ACCEPTED', {'playerName': PLAYERS[tr['receiver']][0], 'playerId': PLAYERS[tr['receiver']][1], 'offerId': '@' + ref}, day, time, not unread)
        check_completion(tr['proposer'], day, time)
        check_completion(tr['receiver'], day, time)
    elif status == 'REFUSED':
        notify(tr['proposer'], 'TRADE_REFUSED', {'playerName': PLAYERS[tr['receiver']][0], 'playerId': PLAYERS[tr['receiver']][1], 'offerId': '@' + ref}, day, time, not unread)


# ---------------------------------------------------------------- market
LISTINGS = {}
WISHES = {}            # (player, card) -> day
WATCHES = {}           # (player, ext) -> day
ALERTS = []


def fee(price):
    return str(price * 10 ** 8 * 5 // 100)


def list_card(ref, seller, card, holo, price, day, time):
    fn, fh = free(seller, card)
    assert (fh if holo else fn) >= 1, (ref, seller, card, fn, fh)
    engaged[seller][card][int(holo)] += 1
    LISTINGS[ref] = {'seller': seller, 'card': card, 'holo': holo, 'price': price, 'day': day, 'time': time,
                     'status': 'ACTIVE', 'closed': None}
    alert_listing(ref, day, time)


def alert_listing(ref, day, time):
    listing = LISTINGS[ref]
    card = CARD[listing['card']]
    ext = EXT[card['ext']]
    for player in PLAYERS:
        if player == listing['seller'] or any(a[0] == player and a[1] == ref for a in ALERTS):
            continue
        direct = (player, card['ref']) in WISHES
        watched = (player, card['ext']) in WATCHES and not owned(player, card['ref'])
        if direct or watched:
            ALERTS.append((player, ref, day, time))
            payload = {'listingId': '@' + ref, 'universe': ext['name'], 'slug': ext['slug'], 'price': listing['price']}
            if direct:
                payload = {'listingId': '@' + ref, 'cardName': card['name'], 'universe': ext['name'], 'slug': ext['slug'], 'price': listing['price']}
            notify(player, 'WISHLIST_LISTED', payload, day, time, not (player == 'barlito' and ref in ALERT_UNREAD))


ALERT_UNREAD = {'listing_benj_l1', 'listing_veli_l4'}
PURCHASES = []


def close_listing(ref, status, day, time):
    listing = LISTINGS[ref]
    engaged[listing['seller']][listing['card']][int(listing['holo'])] -= 1
    listing['status'], listing['closed'] = status, (day, time)


def buy(ref, buyer, day, time):
    listing = LISTINGS[ref]
    assert listing['status'] == 'ACTIVE' and buyer != listing['seller']
    close_listing(ref, 'SOLD', day, time)
    holo = int(listing['holo'])
    debit(listing['seller'], listing['card'], 1 - holo, holo)
    credit(buyer, listing['card'], 1 - holo, holo)
    PURCHASES.append({'ref': nid('market_purchase'), 'listing': ref, 'buyer': buyer, 'day': day, 'time': time, 'status': 'completed'})
    notify(listing['seller'], 'MARKET_SOLD', {'listingId': '@' + ref, 'buyerName': PLAYERS[buyer][0], 'cardName': CARD[listing['card']]['name'] + (' holo' if holo else ''), 'price': listing['price']}, day, time, True)
    check_completion(buyer, day, time)


def failed_purchase(ref, buyer, day, time, reason):
    PURCHASES.append({'ref': nid('market_purchase'), 'listing': ref, 'buyer': buyer, 'day': day, 'time': time, 'status': 'failed', 'reason': reason})


def visible(player, card):
    listed = any(item['card'] == card and item['status'] == 'ACTIVE' for item in LISTINGS.values())
    return owned(player, card) or card in ever[player] or listed


def wish(player, card, day):
    assert visible(player, card), (player, card)
    WISHES[(player, card)] = day


def watch(player, ext_ref, day):
    WATCHES[(player, ext_ref)] = day


# ---------------------------------------------------------------- recycle / fusion
RECYCLES, FUSIONS = [], []


def recycle(player, booster_ref, day, time):
    selection = defaultdict(int)
    points = 0
    while points < 10:
        fitting = [c for c in dupes(player) if POINTS[CARD[c]['rarity']] <= 10 - points]
        assert fitting, (player, day, points)
        card = min(fitting, key=lambda c: RARITY_RANK[CARD[c]['rarity']])
        debit(player, card, 1, 0)
        selection[card] += 1
        points += POINTS[CARD[card]['rarity']]
    RECYCLES.append({'player': player, 'booster': booster_ref, 'cards': dict(selection), 'day': day, 'time': time})
    stock[player][booster_ref] += 1
    notify(player, 'BOOSTER_CREDITED', {'quantity': 1, 'boosterName': BOOSTER[booster_ref]['displayName'], 'channel': 'recycle'}, day, time, True)


def fuse(player, card, day, time):
    fn, _ = free(player, card)
    assert fn >= 10, (player, card, fn)
    debit(player, card, 10, 0)
    credit(player, card, 0, 1)
    FUSIONS.append({'player': player, 'card': card, 'day': day, 'time': time})


# ---------------------------------------------------------------- codes / purchases
CODES, REDEMPTIONS, BOOSTER_PURCHASES = {}, [], []


def code(ref, booster_ref, **kw):
    alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'
    CODES[ref] = dict(booster=booster_ref, code=''.join(RNG.choice(alphabet) for _ in range(12)), uses=0, **kw)


def redeem(ref, player, day, time):
    c = CODES[ref]
    c['uses'] += 1
    assert c.get('maxUses') is None or c['uses'] <= c['maxUses']
    qty = c.get('quantity', 1)
    REDEMPTIONS.append((ref, player, day, time, qty))
    stock[player][c['booster']] += qty
    notify(player, 'BOOSTER_CREDITED', {'quantity': qty, 'boosterName': BOOSTER[c['booster']]['displayName'], 'channel': 'code'}, day, time, True)


def purchase_booster(player, booster_ref, day, time, ok=True):
    b = BOOSTER[booster_ref]
    BOOSTER_PURCHASES.append((player, booster_ref, b['price'], day, time, ok))
    if ok:
        stock[player][booster_ref] += 1


# ================================================================ scenario
EVENTS = []


def at(day, time, fn, *args, **kw):
    EVENTS.append((-day, time, len(EVENTS), lambda: fn(*args, **kw)))


def claim_open(player, booster_ref, day, time):
    claim(player, booster_ref, day, time)
    open_booster(player, booster_ref, day, time[:-1] + '9')


B = lambda slug: 'booster_' + cat.key(slug)  # noqa: E731
CLAIMABLE = [b['ref'] for b in boosters if b['claimable']]

# --- Barlito: targets, exclusions, fusion quotas
cosmonaut = EXT_BY_SLUG['cosmonaut']['ref']
psychedelic = EXT_BY_SLUG['psychedelic']['ref']
VELO = cref('psychedelic', 'Velo')
BANKAI = cref('bleach', 'Barlito Bankai')
CYBERPSYCHO = cref('cyberpunk', 'Benj Cyberpsycho')
GOD = cref('divinity', 'God Almighty')
MONDE_RUCHE = cref('40k', 'Monde-Ruche')
NIVEAU_24 = cref('space-nomad', 'Niveau 24')
SPATIO_GARE = cref('space-nomad', 'Spatio-gare')
TARGETS['barlito'] |= {c['ref'] for c in pool(cosmonaut)} | {c['ref'] for c in pool(psychedelic) if c['ref'] != VELO}
EXCLUDED['barlito'] |= {VELO, BANKAI, CYBERPSYCHO}
QUOTAS['barlito'] = {MONDE_RUCHE: 24, NIVEAU_24: 12, SPATIO_GARE: 10}
PROTECTED |= {MONDE_RUCHE, NIVEAU_24, SPATIO_GARE, VELO, BANKAI, CYBERPSYCHO, GOD}
TARGETS['benj'] |= {BANKAI, CYBERPSYCHO}
TARGETS['juju'] |= {VELO}
TARGETS['collectionneur'] |= {c['ref'] for c in pool(EXT_BY_SLUG['kda']['ref'])} | {GOD}
SCRIPTED_UNIQUE[('barlito', 20)] = cref('magic', 'Demon King Julian')
SCRIPTED_UNIQUE[('juju', 10)] = cref('bleach', 'Veli Kenpachi')

run_a = list(range(44, 28, -1))                     # 16 days: milestones 7 and 14, both chosen
sporadic = [27, 25, 24, 22, 20, 18]
current = list(range(15, 3, -1))                    # + explicit days 3..0 = a 16-day run in progress
plan = ['40k'] * 14 + ['space-nomad'] * 13 + ['psychedelic'] * 10 + ['cosmonaut'] * 9 + \
       ['bleach'] * 4 + ['kda'] * 4 + ['cyberpunk'] * 3 + ['divinity'] * 3 + ['replicant'] * 3 + \
       ['les-fables-d-eldia'] * 2 + ['magic'] * 1 + ['l-album-des-youlz'] * 1
RNG.shuffle(plan)
barlito_days = run_a + sporadic + current
assert len(plan) == 2 * len(barlito_days) - 1
plan.insert(2 * barlito_days.index(20), 'magic')
older = [d for d in range(75, 46, -1) if d not in (68, 60, 52)]
for day in older:
    for time in ('09:15', '21:40'):
        at(day, time, claim_open, 'barlito', RNG.choice(CLAIMABLE), day, time)
for i, day in enumerate(barlito_days):
    at(day, '08:10', claim_open, 'barlito', B(plan[2 * i]), day, '08:10')
    at(day, '20:30', claim_open, 'barlito', B(plan[2 * i + 1]), day, '20:30')

# --- other players: claim + open on their active days
FAVORITES = {
    'juju': ['psychedelic', 'bleach', 'space-nomad', 'magic'],
    'farph': ['40k', 'kda', 'replicant'],
    'benj': ['bleach', 'cyberpunk', 'divinity'],
    'veli': ['kda', 'divinity', 'les-fables-d-eldia'],
    'weeby': ['space-nomad', 'replicant', 'cosmonaut'],
    'collectionneur': ['kda', 'divinity', 'magic'],
}
ACTIVITY = {'farph': 0.55, 'benj': 0.5, 'veli': 0.5, 'weeby': 0.4, 'collectionneur': 0.6}
for player, favorites in FAVORITES.items():
    for day in range(HISTORY_DAYS, -1, -1):
        if player == 'juju':
            active = day != 20
        else:
            active = RNG.random() < ACTIVITY[player] and day >= 1
        if not active:
            continue
        count = 2 if RNG.random() < 0.6 else 1
        for n in range(count):
            slug = RNG.choice(favorites) if RNG.random() < 0.75 else RNG.choice([b[8:] for b in CLAIMABLE])
            if player == 'juju' and day == 10 and n == 0:
                slug = 'bleach'
            hour = 9 + RNG.randint(0, 5) + 6 * n
            at(day, f'{hour:02d}:{RNG.randint(10, 50)}', claim_open, player, B(slug), day, f'{hour:02d}:{RNG.randint(10, 50)}')

# --- codes
code('code_multi', 'booster_magic_event', maxUses=None, batchLabel='magic-launch', createdDay=22)
code('code_assigned', 'booster_psychedelic_event', maxUses=1, quantity=2, assignedTo='barlito', createdDay=0)
code('code_expired', 'booster_l_album_des_youlz_event', maxUses=20, batchLabel='album-launch', expiresDay=3, createdDay=16)
code('code_exhausted', 'booster_cyberpunk_event', maxUses=2, batchLabel='cyberpunk-launch', createdDay=9)
code('code_disabled', 'booster_space_nomad', maxUses=50, batchLabel='giveaway-discord', disabled=True, createdDay=14)
at(20, '18:00', redeem, 'code_multi', 'juju', 20, '18:00')
at(18, '12:00', redeem, 'code_multi', 'farph', 18, '12:00')
at(15, '21:00', redeem, 'code_multi', 'benj', 15, '21:00')
at(12, '19:00', redeem, 'code_expired', 'veli', 12, '19:00')
at(7, '09:30', redeem, 'code_exhausted', 'veli', 7, '09:30')
at(5, '09:00', redeem, 'code_exhausted', 'barlito', 5, '09:00')

# --- booster purchases
at(12, '22:00', purchase_booster, 'collectionneur', 'booster_magic_boosted', 12, '22:00')
at(6, '17:00', purchase_booster, 'farph', 'booster_cyberpunk_boosted', 6, '17:00', ok=False)
at(1, '18:00', purchase_booster, 'juju', 'booster_magic_boosted', 1, '18:00')

SCN = {}


def t2_create():
    offered = []
    for _ in range(2):
        card = lacking('barlito', 'juju', rarity_order=('common', 'uncommon'), exclude=[c for c, _, _ in offered])
        offered.append((card, 1, 0))
    singles = sorted(c for c, (q, h) in inv['barlito'].items() if q == 1 and h == 0 and CARD[c]['extSlug'] in FAVORITES['veli'] and CARD[c]['rarity'] in ('uncommon', 'rare'))
    SCN['Y'] = singles[0]
    trade_create('trade_juju_barlito_accepted', 'juju', 'barlito', offered, [(SCN['Y'], 1, 0)], 12, '18:00')


def t2_accept():
    trade_resolve('trade_juju_barlito_accepted', 'ACCEPTED', 11, '09:00')
    TARGETS['veli'].add(SCN['Y'])


at(12, '18:00', t2_create)
at(11, '09:00', t2_accept)
at(10, '09:00', watch, 'barlito', EXT_BY_SLUG['bleach']['ref'], 10)


def m_withdrawn():
    card = dupes('barlito', rarity='uncommon')[0]
    list_card('listing_barlito_withdrawn', 'barlito', card, False, 25, 10, '10:00')


at(10, '10:00', m_withdrawn)
at(9, '08:00', close_listing, 'listing_barlito_withdrawn', 'WITHDRAWN', 9, '08:00')


def t1_create():
    give = dupes('barlito', rarity='common')[0]
    get = lacking('barlito', 'farph', rarity_order=('rare', 'uncommon'))
    trade_create('trade_barlito_farph_accepted', 'barlito', 'farph', [(give, 2, 0)], [(get, 1, 0)], 9, '21:00')


at(9, '21:00', t1_create)
at(9, '22:00', lambda: wish('barlito', SCN['Y'], 9))
at(8, '12:00', trade_resolve, 'trade_barlito_farph_accepted', 'ACCEPTED', 8, '12:00', unread=True)
at(8, '13:00', watch, 'barlito', psychedelic, 8)


def t5_create():
    give = dupes('juju')[0]
    get = lacking('juju', 'benj', rarity_order=('uncommon', 'common'))
    trade_create('trade_juju_benj_accepted', 'juju', 'benj', [(give, 1, 0)], [(get, 1, 0)], 7, '10:00')


at(7, '10:00', t5_create)
at(7, '18:00', trade_resolve, 'trade_juju_benj_accepted', 'ACCEPTED', 7, '18:00')


def m_sold_by_barlito():
    card = dupes('barlito', rarity='uncommon')[0]
    list_card('listing_barlito_sold', 'barlito', card, False, 35, 7, '12:00')


at(7, '12:00', m_sold_by_barlito)
at(5, '15:00', buy, 'listing_barlito_sold', 'juju', 5, '15:00')
at(6, '13:00', recycle, 'barlito', 'booster_cosmonaut', 6, '13:00')
at(6, '13:01', open_booster, 'barlito', 'booster_cosmonaut', 6, '13:01')


def t3_create():
    give = dupes('barlito', rarity='common')[0]
    get = lacking('barlito', 'veli', rarity_order=('rare', 'uncommon', 'common'))
    trade_create('trade_barlito_veli_refused', 'barlito', 'veli', [(give, 1, 0)], [(get, 1, 0)], 6, '20:00')


at(6, '20:00', t3_create)
at(5, '11:00', trade_resolve, 'trade_barlito_veli_refused', 'REFUSED', 5, '11:00')
at(6, '21:00', watch, 'juju', EXT_BY_SLUG['space-nomad']['ref'], 6)
at(4, '10:00', fuse, 'barlito', MONDE_RUCHE, 4, '10:00')


def t4_create():
    give = dupes('barlito', rarity='common')[1]
    get = lacking('barlito', 'weeby', rarity_order=('uncommon', 'common'))
    trade_create('trade_barlito_warny_cancelled', 'barlito', 'weeby', [(give, 2, 0)], [(get, 1, 0)], 4, '11:00')


at(4, '11:00', t4_create)
at(4, '16:00', trade_resolve, 'trade_barlito_warny_cancelled', 'CANCELLED', 4, '16:00')


def m_collectionneur_sells():
    card = lacking('barlito', 'collectionneur', rarity_order=('rare', 'uncommon'), exclude=[GOD])
    list_card('listing_collectionneur_sold', 'collectionneur', card, False, 45, 4, '09:00')


at(4, '09:00', m_collectionneur_sells)
at(2, '10:30', buy, 'listing_collectionneur_sold', 'barlito', 2, '10:30')


def m_farph_l3():
    card = next(c for c in sorted(inv['farph']) if CARD[c]['rarity'] == 'rare' and free('farph', c)[0] >= 1 and c not in PROTECTED)
    list_card('listing_farph_l3', 'farph', card, False, 120, 4, '19:00')


at(4, '19:00', m_farph_l3)
at(5, '20:00', list_card, 'listing_collectionneur_l5', 'collectionneur', GOD, True, 350, 5, '20:00')
at(3, '12:00', list_card, 'listing_juju_l2', 'juju', VELO, False, 40, 3, '12:00')


def m_warny_l6():
    card = next(c for c in sorted(inv['weeby']) if CARD[c]['extSlug'] == 'space-nomad' and CARD[c]['rarity'] == 'common' and free('weeby', c)[0] >= 1 and c not in PROTECTED)
    list_card('listing_warny_l6', 'weeby', card, False, 15, 3, '18:00')


at(3, '18:00', m_warny_l6)
at(3, '06:00', recycle, 'juju', 'booster_psychedelic', 3, '06:00')

# --- Barlito, last days (explicit)
at(3, '08:00', claim, 'barlito', 'booster_replicant', 3, '08:00')
at(3, '08:01', claim, 'barlito', 'booster_kda', 3, '08:01')
at(3, '08:02', open_booster, 'barlito', 'booster_kda', 3, '08:02')
at(3, '12:00', purchase_booster, 'barlito', 'booster_cyberpunk_boosted', 3, '12:00')
at(3, '12:01', open_booster, 'barlito', 'booster_cyberpunk_boosted', 3, '12:01')
at(2, '09:00', list_card, 'listing_benj_l1', 'benj', BANKAI, True, 300, 2, '09:00')
at(2, '08:00', claim, 'barlito', 'booster_space_nomad', 2, '08:00')
at(2, '08:01', claim, 'barlito', 'booster_psychedelic', 2, '08:01')
at(2, '08:02', open_booster, 'barlito', 'booster_space_nomad', 2, '08:02')
at(2, '21:00', recycle, 'barlito', 'booster_space_nomad', 2, '21:00')


def m_barlito_b2():
    card = next(c for c, (q, h) in sorted(inv['barlito'].items(), key=lambda kv: -RARITY_RANK[CARD[kv[0]]['rarity']])
                if free('barlito', c)[1] >= 1 and q >= 2 and c not in PROTECTED and not CARD[c]['unique'])
    SCN['B2'] = card
    list_card('listing_barlito_b2', 'barlito', card, True, 80, 2, '22:00')


at(2, '22:00', m_barlito_b2)
at(1, '08:00', claim, 'barlito', 'booster_psychedelic', 1, '08:00')
at(1, '08:01', claim, 'barlito', 'booster_space_nomad', 1, '08:01')
at(1, '08:02', open_booster, 'barlito', 'booster_space_nomad', 1, '08:02')
at(1, '10:00', lambda: list_card('listing_veli_l4', 'veli', SCN['Y'], False, 60, 1, '10:00'))
at(1, '12:00', wish, 'barlito', BANKAI, 1)
at(1, '13:00', failed_purchase, 'listing_farph_l3', 'barlito', 1, '13:00', 'Solde insuffisant')
at(1, '19:00', list_card, 'listing_barlito_b1', 'barlito', SPATIO_GARE, False, 20, 1, '19:00')
at(1, '20:00', lambda: wish('juju', SCN['B2'], 1))
at(0, '07:30', open_booster, 'barlito', 'booster_replicant', 0, '07:30')


def t6_create():
    common = next(c for c in sorted(inv['benj']) if owned('barlito', c) and free('benj', c)[0] >= 1 and c not in PROTECTED and not CARD[c]['unique'])
    ask = dupes('barlito', rarity='uncommon')[0]
    trade_create('trade_benj_barlito_pending', 'benj', 'barlito', [(CYBERPSYCHO, 0, 1), (common, 1, 0)], [(ask, 2, 0)], 0, '09:00', unread=True)


def t7_create():
    give = dupes('barlito', rarity='common')[0]
    get = lacking('barlito', 'juju', rarity_order=('rare', 'uncommon'))
    trade_create('trade_barlito_juju_pending', 'barlito', 'juju', [(give, 2, 0)], [(get, 1, 0)], 0, '10:00', unread=True)


at(0, '09:00', t6_create)
at(0, '10:00', t7_create)

def run():
  for _, _, _, fn in sorted(EVENTS, key=lambda e: (e[0], e[1], e[2])):
    fn()


# ================================================================ after the timeline
STREAKS = []
STREAK_CHOICES = ['booster_magic', 'booster_psychedelic', 'booster_cyberpunk', 'booster_l_album_des_youlz', 'booster_kda']


def streaks():
    rotation = 0
    for player in PLAYERS:
        days = sorted(opening_days[player], reverse=True)       # oldest first (days ago)
        runs, run = [], []
        for d in days:
            if run and d != run[-1] - 1:
                runs.append(run)
                run = []
            run.append(d)
        if run:
            runs.append(run)
        for run in runs:
            start = run[0]
            for m in range(7, len(run) + 1, 7):
                day = start - (m - 1)
                time = first_opening_time[(player, day)]
                pending = player == 'barlito' and start == 15 and m == 14
                booster = None
                if not pending:
                    booster = 'booster_cyberpunk' if (player == 'barlito' and start == 15) else STREAK_CHOICES[rotation % len(STREAK_CHOICES)]
                    rotation += 1
                    stock[player][booster] += 1
                    notify(player, 'BOOSTER_CREDITED', {'quantity': 1, 'boosterName': BOOSTER[booster]['displayName'], 'channel': 'streak'}, day, time, True)
                STREAKS.append({'player': player, 'start': start, 'milestone': m, 'day': day, 'time': time, 'booster': booster})
                notify(player, 'STREAK_REWARD_AVAILABLE', {'milestone': m}, day, time, not pending)


def checks():
    for player, quotas in QUOTAS.items():
        assert all(v == 0 for v in quotas.values()), (player, quotas)
    for player, targets in TARGETS.items():
        assert targets <= drawn[player], (player, targets - drawn[player])
    for player in PLAYERS:
        for card, (q, h) in inv[player].items():
            fn, fh = free(player, card)
            assert q >= h >= 0 and fn >= 0 and fh >= 0, (player, card, q, h, fn, fh)
    assert ('barlito', cosmonaut) in rewarded
    assert ('barlito', psychedelic) not in rewarded and not owned('barlito', VELO)
    assert normals('barlito', SPATIO_GARE) == 10 and free('barlito', SPATIO_GARE)[0] == 9
    assert free('barlito', MONDE_RUCHE)[0] >= 10 and free('barlito', NIVEAU_24)[0] >= 10
    assert not owned('barlito', CYBERPSYCHO) and not owned('barlito', BANKAI)
    assert 'card_magic_demon_king_julian' in drawn['barlito']


def summary():
    lines = []
    lines.append(f'openings {len(out["opening"])}, opening cards {len(out["opening_card"])}, claims {len(out["claim"])}')
    lines.append(f'rewards {sorted((p, EXT[e]["slug"]) for p, e in rewarded)}')
    lines.append(f'streak rewards {len(STREAKS)}, notifications {len(notifications)}, alerts {ALERTS}')
    lines.append(f'barlito stock {dict((k, v) for k, v in stock["barlito"].items() if v)}')
    rec = sum(max(0, free("barlito", c)[0] - 1) for c in inv["barlito"] if c not in PROTECTED)
    lines.append(f'barlito recyclable extra normals {rec}, fusable {[c for c in inv["barlito"] if free("barlito", c)[0] >= 10]}')
    lines.append(f'scn {SCN}')
    for ref, tr in TRADES.items():
        lines.append(f'{ref}: {tr["offered"]} vs {tr["requested"]} {tr["status"]} new={tr["new"]}')
    for ref, li in LISTINGS.items():
        lines.append(f'{ref}: {li["seller"]} {li["card"]} holo={li["holo"]} {li["price"]} {li["status"]}')
    return '\n'.join(lines)


# ================================================================ yaml
def payload_yaml(payload):
    items = []
    for k, v in payload.items():
        if isinstance(v, int):
            items.append(f'{k}: {v}')
        else:
            items.append(f'{k}: {yq(v)}')
    return '{ ' + ', '.join(items) + ' }'


def write(outdir):
    users = {p: f'discord_user_{p}' for p in PLAYERS}
    files = {}
    files['Opening.yaml'] = ['# Historique d\'ouvertures simulé (generate : voir docs/dev-fixtures.md) : réclamations quotidiennes,', '# ouvertures et cartes tirées, cohérents avec Collection.yaml.',
                             'App\\Entity\\BoosterClaim:'] + out['claim'] + ['', 'App\\Entity\\BoosterOpening:'] + out['opening'] + ['', 'App\\Entity\\BoosterOpeningCard:'] + out['opening_card']

    lines = ['# État final des inventaires après la simulation (quantity inclut holoQuantity).', 'App\\Entity\\UserCard:']
    for player in PLAYERS:
        for card in sorted(inv[player]):
            q, h = inv[player][card]
            if q == 0 and card not in ever[player]:
                continue
            if q == 0:
                continue
            lines.append(f'    uc_{player}_{card[5:]}:\n        discordUser: \'@{users[player]}\'\n        card: \'@{card}\'\n        quantity: {q}\n        holoQuantity: {h}')
    lines += ['', 'App\\Entity\\UserBooster:']
    for player in PLAYERS:
        for booster, n in sorted(stock[player].items()):
            if n > 0:
                lines.append(f'    ub_{player}_{booster[8:]}:\n        discordUser: \'@{users[player]}\'\n        booster: \'@{booster}\'\n        quantity: {n}')
    files['Collection.yaml'] = lines

    lines = ['App\\Entity\\TradeOffer:']
    for ref, tr in TRADES.items():
        lines.append(f'    {ref}:\n        proposer: \'@{users[tr["proposer"]]}\'\n        receiver: \'@{users[tr["receiver"]]}\'\n        createdAt: {tm(tr["day"], tr["time"])}')
        if tr['status'] != 'PENDING':
            d, ti = tr['resolved']
            lines.append(f'        __calls:\n            - resolve: [\'<(App\\Enum\\Trade\\TradeOfferStatusEnum::{tr["status"]})>\', {t(d, ti)}]')
    lines += ['', 'App\\Entity\\TradeOfferLine:']
    for ref, tr in TRADES.items():
        for side, rows in (('OFFERED', tr['offered']), ('REQUESTED', tr['requested'])):
            for card, n, h in rows:
                lines.append(f'    {ref}_{side.lower()}_{card[5:]}:\n        tradeOffer: \'@{ref}\'\n        side: \'<(App\\Enum\\Trade\\TradeOfferSideEnum::{side})>\'\n        card: \'@{card}\'\n        normalQuantity: {n}\n        holoQuantity: {h}')
                if (side, card) in tr['new']:
                    lines.append(f'        newForTaker: {"true" if tr["new"][(side, card)] else "false"}')
    files['Trade.yaml'] = lines

    lines = ['App\\Entity\\MarketListing:']
    for ref, li in LISTINGS.items():
        lines.append(f'    {ref}:\n        __construct: [\'@{users[li["seller"]]}\', \'@{li["card"]}\', {"true" if li["holo"] else "false"}, {li["price"]}]\n        createdAt: {tm(li["day"], li["time"])}')
        if li['status'] != 'ACTIVE':
            d, ti = li['closed']
            lines.append(f'        __calls:\n            - close: [\'<(App\\Enum\\Market\\MarketListingStatusEnum::{li["status"]})>\', {t(d, ti)}]')
    lines += ['', '# commission 5 % (CoinSettings.marketFeePercent) en unités mineures : prix × 10^8 × 5 / 100', 'App\\Entity\\MarketPurchase:']
    for pu in PURCHASES:
        li = LISTINGS[pu['listing']]
        lines.append(f'    {pu["ref"]}:\n        __construct: [\'@{pu["listing"]}\', \'@{users[pu["buyer"]]}\', \'@{users[li["seller"]]}\', {li["price"]}, \'{fee(li["price"])}\', {t(pu["day"], pu["time"])}]\n        __calls:')
        if pu['status'] == 'completed':
            lines.append(f'            - markCardTransferred: [\'<uuid()>\']\n            - complete: [\'<uuid()>\', {t(pu["day"], pu["time"])}]')
        else:
            lines.append(f'            - fail: [{yq(pu["reason"])}, {t(pu["day"], pu["time"])}]')
    files['Market.yaml'] = lines

    lines = ['App\\Entity\\WishlistEntry:']
    for (player, card), day in WISHES.items():
        lines.append(f'    wish_{player}_{card[5:]}:\n        __construct: [\'@{users[player]}\', \'@{card}\']\n        createdAt: {tm(day, "22:30")}')
    lines += ['', 'App\\Entity\\WishlistUniverse:']
    for (player, ext), day in WATCHES.items():
        lines.append(f'    watch_{player}_{ext[10:]}:\n        __construct: [\'@{users[player]}\', \'@{ext}\']\n        createdAt: {tm(day, "09:00")}')
    lines += ['', 'App\\Entity\\WishlistAlert:']
    for player, ref, day, time in ALERTS:
        lines.append(f'    alert_{player}_{ref}:\n        __construct: [\'@{users[player]}\', \'@{ref}\', {t(day, time)}]')
    files['Wishlist.yaml'] = lines

    lines = ['App\\Entity\\BoosterPurchase:']
    for i, (player, booster, price, day, time, ok) in enumerate(BOOSTER_PURCHASES, 1):
        lines.append(f'    booster_purchase_{i}:\n        __construct: [\'@{users[player]}\', \'@{booster}\', {price}, {t(day, time)}]\n        __calls:')
        lines.append(f'            - complete: [\'<uuid()>\', {t(day, time)}]' if ok else f'            - fail: [\'Solde insuffisant\', {t(day, time)}]')
    lines += ['', 'App\\Entity\\UniverseCompletionReward:']
    for (player, ext), (day, time) in rewarded.items():
        e = EXT[ext]
        lines.append(f'    universe_reward_{player}_{ext[10:]}:\n        __construct: [\'@{users[player]}\', \'@{ext}\', 500, {t(day, time)}]\n        __calls:\n            - markPaid: [\'<uuid()>\', {t(day, time)}]')
        notify(player, 'UNIVERSE_COMPLETED', {'universe': e['name'], 'slug': e['slug'], 'amount': 500}, day, time, True)
    lines += ['', 'App\\Entity\\StreakReward:']
    for s in STREAKS:
        lines.append(f'    streak_{s["player"]}_{s["start"]}_{s["milestone"]}:\n        __construct: [\'@{users[s["player"]]}\', \'<parisDay({s["start"]})>\', {s["milestone"]}, {t(s["day"], s["time"])}]')
        if s['booster']:
            lines.append(f'        __calls:\n            - choose: [\'@{s["booster"]}\', {t(s["day"], s["time"])}]')
    lines += ['', 'App\\Entity\\RecycleOperation:']
    for i, r in enumerate(RECYCLES, 1):
        points = sum(POINTS[CARD[c]['rarity']] * n for c, n in r['cards'].items())
        lines.append(f'    recycle_{i}:\n        __construct: [\'@{users[r["player"]]}\', \'@{r["booster"]}\', {points}, 1, {t(r["day"], r["time"])}]')
    lines += ['', 'App\\Entity\\RecycleOperationCard:']
    for i, r in enumerate(RECYCLES, 1):
        for c, n in r['cards'].items():
            lines.append(f'    recycle_{i}_{c[5:]}:\n        __construct: [\'@recycle_{i}\', \'@{c}\', {n}, 0]')
    lines += ['', 'App\\Entity\\FusionOperation:']
    for i, f in enumerate(FUSIONS, 1):
        lines.append(f'    fusion_{i}:\n        __construct: [\'@{users[f["player"]]}\', \'@{f["card"]}\', 1, 10, 1, {t(f["day"], f["time"])}]')
    lines += ['', 'App\\Entity\\BoosterCode:']
    for ref, c in CODES.items():
        lines.append(f'    {ref}:\n        code: {yq(c["code"])}\n        booster: \'@{c["booster"]}\'\n        quantity: {c.get("quantity", 1)}\n        maxUses: {c["maxUses"] if c["maxUses"] is not None else "null"}')
        if c.get('batchLabel'):
            lines.append(f'        batchLabel: {yq(c["batchLabel"])}')
        if c.get('assignedTo'):
            lines.append(f'        assignedTo: \'@{users[c["assignedTo"]]}\'')
        if c.get('expiresDay') is not None:
            lines.append(f'        expiresAt: {t(c["expiresDay"], "00:00")}')
        if c.get('disabled'):
            lines.append('        disabled: true')
        lines.append(f'        createdAt: {tm(c["createdDay"], "08:00")}')
        if c['uses']:
            lines.append('        __calls:\n' + '\n'.join('            - incrementUses: []' for _ in range(c['uses'])))
    lines += ['', 'App\\Entity\\BoosterCodeRedemption:']
    for ref, player, day, time, qty in REDEMPTIONS:
        lines.append(f'    redemption_{ref}_{player}:\n        __construct: [\'@{ref}\', \'@{users[player]}\', {t(day, time)}, {qty}]')
    files['Economy.yaml'] = lines

    # code sent to Barlito + broadcast announcement
    c = CODES['code_assigned']
    notify('barlito', 'BOOSTER_CODE', {'code': c['code'], 'quantity': 2, 'boosterName': BOOSTER[c['booster']]['displayName'], 'message': 'Merci pour les retours sur le marché, voilà de quoi finir Psychedelic !'}, 0, '08:00', False)
    notify(None, 'ANNOUNCEMENT', {'title': 'Le marché tourne à plein régime', 'message': 'Pensez à mettre vos doublons en vente : la commission reste à 5 % ce mois-ci.', 'link': '/marche'}, 0, '07:00', False)
    lines = ['App\\Entity\\Announcement:',
             f'    announcement_market:\n        __construct: [\'<(App\\Enum\\Notification\\NotificationTypeEnum::ANNOUNCEMENT)>\', \'Le marché tourne à plein régime\', \'Pensez à mettre vos doublons en vente : la commission reste à 5 % ce mois-ci.\', \'/marche\', \'@discord_user_barlito\', [], {len(PLAYERS)}, {t(0, "07:00")}]',
             f'    announcement_code_barlito:\n        __construct: [\'<(App\\Enum\\Notification\\NotificationTypeEnum::BOOSTER_CODE)>\', {yq("2 packs « " + BOOSTER[c["booster"]]["displayName"] + " »")}, \'Merci pour les retours sur le marché, voilà de quoi finir Psychedelic !\', null, \'@discord_user_barlito\', [\'@discord_user_barlito\'], 1, {t(0, "08:00")}]',
             '', 'App\\Entity\\Notification:']
    reads = []
    for i, n in enumerate(sorted(notifications, key=lambda n: (-n['day'], n['time'])), 1):
        recipient = f"'@{users[n['recipient']]}'" if n['recipient'] else 'null'
        lines.append(f'    notification_{i}:\n        __construct: [{recipient}, \'<(App\\Enum\\Notification\\NotificationTypeEnum::{n["type"]})>\', {payload_yaml(n["payload"])}, {t(n["day"], n["time"])}]')
        if n['read'] and n['recipient']:
            lines.append(f'        __calls:\n            - markRead: [{t(n["day"], n["time"])}]')
        if n['recipient'] is None and n['type'] == 'UNIQUE_PULLED':
            reads.append(i)
    lines += ['', '# broadcasts « 1/1 tirée » déjà ouverts par Barlito ; l\'annonce du jour reste non lue', 'App\\Entity\\NotificationBroadcastRead:']
    for i in reads:
        lines.append(f'    broadcast_read_{i}:\n        __construct: [\'@notification_{i}\', \'@discord_user_barlito\', {t(0, "06:00")}]')
    files['Notification.yaml'] = lines

    for name, content in files.items():
        open(os.path.join(outdir, name), 'w').write('\n'.join(content) + '\n')
    cat.write_catalogue(outdir, extensions, cards, boosters)
    json.dump({k: dict(sorted(v.items())) for k, v in assets.items()}, open(os.path.join(outdir, '..', 'dev-assets.json'), 'w'), indent=2, ensure_ascii=False)


if __name__ == '__main__':
    run()
    streaks()
    checks()
    print(summary())
    if len(sys.argv) > 1:
        write(sys.argv[1])

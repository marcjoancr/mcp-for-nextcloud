// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '../support/app-fixture.js'

const path = '/settings/tool-permissions'
function fixture(app) {
    const people = { staff: 'Sam', alice: 'Alice', bob: 'Bob' }
    const groupNames = { staff: 'Staff', external: 'External' }
    const rules = {
        'group/staff': { files_read: 'allow', files_edit: 'allow' },
        'group/external': { files_edit: 'deny' },
        'user/alice': { files_upload: 'allow' },
        'user/bob': { _service: 'deny' },
    }
    let revision = 0, loseConfirmation = false
    const receipts = new Map()
    const groups = Object.entries(groupNames).map(([id, displayName]) => ({ id, displayName }))
    function row(kind, id, name) {
        const assigned = rules[kind + '/' + id]?.[name] || 'inherit'
        if (assigned !== 'inherit') return { state: assigned, effective: assigned === 'allow', source: kind === 'group' ? 'group' : 'individual', origin: kind === 'group' ? 'Group assignment' : 'Individual assignment', groupIds: [] }
        const winners = kind === 'user' ? groups.filter(g => rules['group/' + g.id]?.[name] === 'deny') : []
        if (kind === 'user' && !winners.length) winners.push(...groups.filter(g => rules['group/' + g.id]?.[name] === 'allow'))
        const effective = winners.length ? rules['group/' + winners[0].id][name] === 'allow' : name === '_service' || name === 'files_read'
        return { state: assigned, effective, source: winners.length ? 'group' : 'default', origin: winners.length ? 'Group assignment' : 'Default', groupIds: winners.map(g => g.id) }
    }
    function snapshot(kind, id) {
        const entries = [['files_read','Read file'], ['files_edit','Edit file'], ['files_upload','Upload file']]
        if (kind === 'group') entries.push(['notes_read','Read note'])
        return { kind, subject: id, groups: kind === 'user' ? groups : [], revision: String(revision), service: row(kind,id,'_service'),
            tools: entries.map(([name,title]) => ({ name,title,description:'Description of ' + title,module:name.split('_')[0],operation:name.split('_')[1], ...row(kind,id,name) })) }
    }
    app.on('GET', path + '/subjects', ({ query }) => {
        const entries = Object.entries(query.get('kind') === 'group' ? groupNames : people).filter(kv => kv.join(' ').toLowerCase().includes(query.get('search').toLowerCase()))
        return {body:{subjects:entries.map(([id,displayName]) => ({id,displayName})),hasMore:false}}
    })
    app.on('GET', path, ({query}) => ({body:snapshot(query.get('kind'),query.get('subject'))}))
    app.on('GET', path + '/origin', ({query}) => ({body:{revision:String(revision),individual:row('user',query.get('subject'),query.get('tool')),
        groups:groups.map(g => ({...g,...row('group',g.id,query.get('tool'))}))}}))
    app.on('POST', path, ({body}) => {
        if (body.revision !== String(revision)) return {status:409}
        const {kind,subject,changes} = body
        const key = kind + '/' + subject
        const original = rules[key] || {}
        rules[key] = {...original,...changes}
        if (body.preview) {
            const tools = Object.fromEntries(Object.keys(changes).map(n => [n,row(kind,subject,n)]))
            rules[key] = original
            const previewId = 'receipt-' + receipts.size
            receipts.set(previewId, JSON.stringify([kind,subject,changes,revision]))
            return {body:{revision:String(revision),previewId,impact:{tools,summary:'Verified impact'}}}
        }
        expect(receipts.get(body.previewId)).toBe(JSON.stringify([kind,subject,changes,revision]))
        revision++
        if (loseConfirmation) return {status:503, body:{uncertain:true}}
        return {body:snapshot(kind,subject)}
    })
    return { bump: () => revision++, loseConfirmation: () => { loseConfirmation = true } }
}
const cell = (page, key, name) => page.locator('td[data-column-key="' + key + '"][data-tool="' + name + '"]')
const picker = page => page.locator('#mcp-tools-subject-search')
const writes = app => app.calls.filter(c => c.path === path && c.method === 'POST')
async function select(page, kind, search, label) {
    await picker(page).fill(search)
    await page.locator('#mcp-tools-subject').getByRole('group',{name:kind}).getByRole('button',{name:label,exact:true}).click()
    await expect(page.locator('#mcp-tools-picker')).toBeHidden()
}
async function open(page,app) {
    const state = fixture(app)
    await app.open('/admin#mcp-tool-permissions')
    await expect(page.locator('#mcp-tools-editor')).toBeVisible()
    await expect(page.locator('#mcp-tools-picker')).toBeHidden()
    await page.getByRole('button', { name: 'Choose comparison for Staff' }).click()
    return state
}
async function add(page,kind,search,label) {
    await page.locator('#mcp-tools-add').click()
    await select(page,kind,search,label)
}

test('replace any header with a group or person without changing the other column, search or grants', async ({page,app})=>{
    await open(page,app)
    await select(page,'Groups','staff','Staff · staff')
    await add(page,'People','alice','Alice · alice')
    await page.locator('#mcp-tools-filter').fill('Edit file')
    await expect(cell(page,'1','files_edit').getByRole('combobox')).toHaveValue('allow')
    await expect(cell(page,'2','files_edit')).toContainText('External')
    await page.getByRole('button',{name:'Choose comparison for Staff'}).click()
    await select(page,'People','staff','Sam · staff')
    await expect(cell(page,'1','files_edit')).toContainText('Blocked')
    await expect(cell(page,'2','files_edit')).toContainText('External')
    await expect(page.locator('#mcp-tools-filter')).toHaveValue('Edit file')
    expect(writes(app)).toEqual([])
})

test('group and person columns line up with their values and keep removal in the matching header', async ({page,app}) => {
    await open(page,app)
    await select(page,'Groups','staff','Staff · staff')
    await add(page,'Groups','external','External · external')
    await add(page,'People','alice','Alice · alice')
    await page.locator('#mcp-tools-filter').fill('Edit file')
    const widths = []
    for (const key of ['1','2','3']) {
        const header = page.locator('th.mcp-compare-identity[data-column-key="' + key + '"]')
        const box = await header.boundingBox()
        const row = await cell(page,key,'files_edit').boundingBox()
        expect(Math.abs(row.x - box.x)).toBeLessThanOrEqual(1)
        expect(Math.abs(row.width - box.width)).toBeLessThanOrEqual(1)
        widths.push(box.width)
    }
    expect(Math.max(...widths) - Math.min(...widths)).toBeLessThanOrEqual(1)
    const header = page.locator('.mcp-compare-identity').filter({hasText:'External'})
    await header.locator('summary').click()
    await header.getByRole('button',{name:'Remove External'}).click()
    await expect(page.locator('.mcp-compare-identity')).toHaveCount(2)
    await expect(cell(page,'3','files_edit')).toContainText('External')
    expect(writes(app)).toEqual([])
})

test('an individual cell explains contributing groups and can compare only those groups', async ({page,app})=>{
    await open(page,app); await select(page,'People','alice','Alice · alice')
    await page.locator('#mcp-tools-filter').fill('Edit file')
    await cell(page,'1','files_edit').getByRole('button').click()
    const detail=page.locator('#mcp-tools-detail')
    await expect(detail).toContainText('Description of Edit file')
    await expect(detail.getByRole('combobox')).toHaveCount(0)
    await expect(detail.locator('xpath=ancestor::tr/preceding-sibling::tr[1]')).toHaveAttribute('data-tool','files_edit')
    await expect(detail).toContainText('Staff · Allowed')
    await expect(detail).toContainText('External · Blocked')
    await detail.getByRole('checkbox',{name:'External'}).check()
    await detail.getByRole('button',{name:'Compare selected groups'}).click()
    await expect(cell(page,'2','files_edit').getByRole('combobox')).toHaveValue('deny')
    expect(writes(app)).toEqual([])
})

test('remove a personal exception only after reviewing the inherited result', async ({page,app})=>{
    await open(page,app); await select(page,'People','alice','Alice · alice')
    await page.locator('#mcp-tools-exceptions').check()
    await page.locator('#mcp-tools-filter').fill('file')
    await expect(cell(page,'1','files_read')).toHaveCount(0)
    const upload=cell(page,'1','files_upload')
    await expect(upload).toContainText('Individual exception')
    await upload.getByRole('combobox').selectOption('inherit')
    await upload.getByRole('combobox').focus()
    await upload.getByRole('combobox').selectOption('allow')
    await expect(page.locator('#mcp-tools-savebar')).toBeHidden()
    await upload.getByRole('combobox').selectOption('inherit')
    await expect(upload.getByRole('combobox')).toBeFocused()
    await expect(upload.getByRole('combobox')).toHaveValue('inherit')
    await expect(upload).toContainText('Allowed')
    expect(writes(app)).toEqual([])
    await page.getByRole('button',{name:'Review changes'}).click()
    await expect(page.locator('#mcp-tools-changes')).toContainText('Blocked')
    await page.getByRole('button',{name:'Save rules for Alice'}).click()
    await expect(upload).toHaveCount(0)
    expect(writes(app).map(c=>c.body.preview)).toEqual([true,false])
    expect(writes(app)[1].body.changes).toEqual({files_upload:'inherit'})
})

test('bulk changes target only checked rows and columns and use a fresh receipt for each identity', async ({page,app})=>{
    await open(page,app); await select(page,'Groups','staff','Staff · staff')
    await add(page,'People','alice','Alice · alice')
    await page.locator('#mcp-tools-filter').fill('Read file')
    await page.getByRole('button',{name:'Edit multiple rules'}).click()
    await page.getByRole('checkbox',{name:'Select Read file'}).check()
    const bulk=page.locator('#mcp-tools-bulk-editor')
    await expect(bulk.getByRole('combobox')).toBeDisabled()
    await bulk.getByLabel('Group · Staff').check()
    await bulk.getByLabel('Person · Alice').check()
    await bulk.getByRole('combobox').selectOption('deny')
    expect(writes(app)).toEqual([])
    await page.getByRole('button',{name:'Review changes'}).click()
    await page.getByRole('button',{name:'Save rules for Staff'}).click()
    await expect(page.locator('#mcp-tools-editor-status')).toContainText('Staff')
    await expect(page.locator('#mcp-tools-changes')).not.toContainText('Group · Staff')
    await page.getByRole('button',{name:'Review changes'}).click()
    await page.getByRole('button',{name:'Save rules for Alice'}).click()
    await expect(page.locator('#mcp-tools-savebar')).toBeHidden()
    expect(writes(app).map(c=>[c.body.kind,c.body.revision,c.body.preview])).toEqual([['group','0',true],['group','0',false],['user','1',true],['user','1',false]])
})

test('service and bulk rule selectors skip unavailable tools and discard without writes', async ({page,app}) => {
    await open(page,app)
    await select(page,'Groups','staff','Staff · staff')
    await add(page,'People','bob','Bob · bob')
    const service = cell(page,'2','_service')
    await expect(service.getByRole('combobox')).toHaveValue('deny')
    await service.getByRole('button').click()
    await expect(page.locator('#mcp-tools-detail')).toContainText('Controls whether MCP is available')
    await service.getByRole('combobox').selectOption('inherit')
    await expect(service.locator('.mcp-compare-word')).toHaveText('Blocked')
    await expect(page.locator('#mcp-tools-detail')).toBeHidden()
    await page.locator('#mcp-tools-discard').click()
    await expect(service.getByRole('combobox')).toHaveValue('deny')

    await page.locator('#mcp-tools-filter').fill('Read note')
    await page.locator('#mcp-tools-bulk').click()
    await page.getByRole('checkbox', { name:'Select Read note' }).check()
    const bulk = page.locator('#mcp-tools-bulk-editor')
    await bulk.getByLabel('Person · Bob').check()
    await expect(bulk.getByRole('combobox')).toBeDisabled()
    await expect(cell(page,'2','notes_read').getByRole('combobox')).toHaveCount(0)
    await bulk.getByLabel('Group · Staff').check()
    await expect(bulk).toContainText('Selected rules: 1')
    await bulk.getByRole('combobox').selectOption('deny')
    await expect(page.locator('#mcp-tools-changes li')).toHaveCount(1)
    await expect(page.locator('#mcp-tools-changes')).toContainText('Staff')
    await page.locator('#mcp-tools-discard').click()
    expect(writes(app)).toEqual([])
})

test('phone switches between mixed columns; person block wins and missing apps are not editable', async ({page,app})=>{
    await page.setViewportSize({width:375,height:812})
    await open(page,app); await select(page,'Groups','staff','Staff · staff')
    await add(page,'People','bob','Bob · bob')
    await page.locator('#mcp-tools-filter').fill('Read file')
    await expect(cell(page,'2','files_read')).toContainText('Service blocked')
    const mobileGrid = page.locator('.mcp-compare-table-wrap')
    expect(await mobileGrid.evaluate(el => el.scrollWidth <= el.clientWidth + 1)).toBe(true)
    await expect(cell(page,'1','files_read')).toBeHidden()
    await page.locator('.mcp-compare-catalog-row').first().evaluate(el => el.scrollIntoView({ block: 'start' }))
    const currentHeader = mobileGrid.locator('th.mcp-compare-identity')
    await expect(currentHeader).toContainText('Bob')
    await expect.poll(() => currentHeader.evaluate(th => {
        const { x, y, width, height } = th.getBoundingClientRect()
        return th.contains(document.elementFromPoint(x + width / 2, y + height / 2))
    })).toBe(true)
    await cell(page,'2','files_read').getByRole('button').click()
    await expect(page.locator('#mcp-tools-detail')).toContainText('Read file')
    await page.locator('#mcp-tools-detail').getByRole('button', { name: 'Close', exact: true }).click()
    await page.locator('.mcp-compare-mobile-column').selectOption('1')
    await expect(cell(page,'1','files_read')).toBeVisible()
    await page.locator('#mcp-tools-filter').fill('Read note')
    await expect(cell(page,'2','notes_read')).toHaveCount(0)
    const fits=await page.evaluate(()=>document.documentElement.scrollWidth<=document.documentElement.clientWidth)
    expect(fits).toBe(true)
})

test('differences compare the displayed decisions and keep normal plus operation-specific routes reachable', async ({page,app})=>{
    await open(page,app); await select(page,'Groups','staff','Staff · staff'); await add(page,'People','alice','Alice · alice')
    await page.locator('#mcp-tools-differences').check()
    await page.locator('#mcp-tools-filter').fill('file')
    await expect(cell(page,'1','files_read')).toHaveCount(0)
    await expect(cell(page,'2','files_edit')).toBeVisible()
    await cell(page,'2','files_edit').getByRole('button').click()
    await page.locator('#mcp-tools-detail summary').click()
    await page.getByRole('button',{name:'Edit Files operations: edit'}).click()
    await expect(page.locator('#mcp-tools-advanced')).toHaveAttribute('open','')
    await expect(page.locator('#mcp-search')).toHaveValue('alice')
    await expect.poll(() => app.calls.filter(call => call.path === '/api/grants').at(-1).query).toEqual({ uid: 'alice' })
    await expect(page.locator('#mcp-app')).toHaveValue('files')
    await page.locator('#mcp-tools-advanced > summary').click()
    await expect(page.locator('#mcp-tools-advanced')).toBeHidden()
    await expect(page.getByRole('button', { name: 'Edit Files operations: edit' })).toBeFocused()
})

for (const conflict of [true, false]) {
    test(conflict ? 'a stale preview requires a fresh review' : 'a lost save response reloads the accepted rule without replaying it', async ({ page, app }) => {
        const state = await open(page, app)
        await select(page, 'Groups', 'staff', 'Staff · staff')
        // Nextcloud group and user IDs occupy independent namespaces.
        await add(page, 'People', 'staff', 'Sam · staff')
        await page.locator('#mcp-tools-filter').fill('Read file')
        await cell(page, '1', 'files_read').getByRole('combobox').selectOption('deny')
        await page.getByRole('button', { name: 'Review changes' }).click()
        if (conflict) state.bump()
        else state.loseConfirmation()
        await page.getByRole('button', { name: 'Save rules for Staff' }).click()
        await expect(page.locator('#mcp-tools-editor-error')).toContainText(conflict ? 'Permissions changed elsewhere' : 'could not be confirmed')
        expect(writes(app).map(c => [c.body.subject, c.body.kind, c.body.preview])).toEqual([
            ['staff', 'group', true], ['staff', 'group', false],
        ])
        if (conflict) {
            await expect(page.getByRole('button', { name: 'Review changes' })).toBeVisible()
            await expect(cell(page, '1', 'files_read').getByRole('combobox')).toHaveValue('deny')
            await expect(cell(page, '1', 'files_read')).toContainText('Pending: Block')
        } else {
            await expect(page.locator('#mcp-tools-savebar')).toBeHidden()
            await expect(cell(page, '1', 'files_read').getByRole('combobox')).toHaveValue('deny')
            await expect(cell(page, '2', 'files_read')).toContainText('Blocked')
        }
    })
}


test('a group operation link opens its own catalog and focuses an operation', async ({ page, app }) => {
    await open(page, app)
    app.on('GET', '/api/group-directory/detail', ({ query }) => ({ body: {
        groupId: query.get('gid'), trusted: true, exists: true, state: 'active', grants: {},
        revision: '0', catalog: { files: ['read', 'edit'] }, counts: { members: 1, overrides: 0 },
    } }))
    await select(page, 'Groups', 'staff', 'Staff · staff')
    await page.locator('#mcp-tools-filter').fill('Edit file')
    await cell(page, '1', 'files_edit').getByRole('button').click()
    await page.locator('#mcp-tools-detail summary').click()
    await page.getByRole('button', { name: 'Edit Files operations: edit' }).click()
    await expect(page.locator('#mcp-group-detail h3')).toHaveText('Staff')
    await expect(page.locator('#mcp-group-detail input[value="edit"]')).toBeFocused()
    await expect(page.locator('#mcp-block-matrix')).toBeHidden()
    expect(writes(app)).toEqual([])
})

test('a header opens beside its column and a person can be selected without searching', async ({ page, app }) => {
    fixture(app)
    await app.open('/admin#mcp-tool-permissions')
    const header = page.getByRole('button', { name: 'Choose comparison for Staff' })
    await expect(header).toBeVisible()
    await header.focus()
    await page.keyboard.press('Enter')
    const popup = page.getByRole('dialog', { name: 'Choose a group or person' })
    await expect(popup.getByRole('searchbox')).toBeFocused()
    await expect(popup.getByRole('group', { name: 'Groups' })).toBeVisible()
    const people = popup.getByRole('group', { name: 'People' })
    await expect(people.getByRole('button', { name: 'Alice · alice' })).toBeVisible()
    const anchor = await header.boundingBox(), box = await popup.boundingBox()
    expect(Math.min(Math.abs(box.y - anchor.y - anchor.height), Math.abs(box.y + box.height - anchor.y))).toBeLessThan(8)
    await page.keyboard.press('ArrowDown')
    await expect(popup.getByRole('button', { name: 'Staff · staff' })).toBeFocused()
    await page.keyboard.press('Escape')
    await expect(header).toBeFocused()
    await expect(header).toHaveAttribute('aria-expanded', 'false')
    await header.click()
    await expect(popup).toBeVisible()
    await header.click()
    await expect(popup).toBeHidden()
    await header.click()
    await people.getByRole('button', { name: 'Alice · alice' }).click()
    await expect(page.getByRole('button', { name: 'Choose comparison for Alice' })).toBeFocused()
    expect(writes(app)).toEqual([])
})

test('a late initial directory response cannot replace an explicit choice', async ({page,app}) => {
    fixture(app)
    let finish
    let first = true
    app.on('GET', path + '/subjects', ({ query }) => {
        if (first) {
            first = false
            return new Promise(resolve => { finish = () => resolve({ body: { subjects: [{id:'staff',displayName:'Staff'}] } }) })
        }
        return {body:{subjects: query.get('kind') === 'user' ? [{id:'alice',displayName:'Alice'}] : [],hasMore:false}}
    })
    await app.open('/admin#mcp-tool-permissions')
    await expect.poll(() => !!finish).toBe(true)
    await page.getByRole('button', {name:'Choose a group or person'}).click()
    await page.getByRole('dialog').getByRole('button', {name:'Alice · alice'}).click()
    finish()
    await expect(page.getByRole('button', {name:'Choose comparison for Alice'})).toBeVisible()
    expect(app.calls.filter(c=>c.path===path && c.query.subject==='staff')).toEqual([])
})

test('on a phone the header picker fits the viewport and tapping outside closes it', async ({page,app}) => {
    await page.setViewportSize({width:375,height:812})
    await open(page,app)
    const box=await page.getByRole('dialog').boundingBox()
    expect(box.x).toBeGreaterThanOrEqual(0)
    expect(box.x+box.width).toBeLessThanOrEqual(375)
    await page.locator('#mcp-tools-add').click()
    await expect(page.getByRole('dialog',{name:'Choose a group or person'})).toBeVisible()
    await page.locator('#mcp-tools-heading').click()
    await expect(page.getByRole('dialog')).toBeHidden()
})

test('a directory without groups starts with a person and does not require a search', async ({ page, app }) => {
    fixture(app)
    app.on('GET', path + '/subjects', ({ query }) => ({body: {
        subjects: query.get('kind') === 'group' ? [] : [{ id:'alice', displayName:'Alice' }],
        hasMore:false,
    }}))
    await app.open('/admin#mcp-tool-permissions')
    await expect(page.getByRole('button', { name:'Choose comparison for Alice' })).toBeVisible()
    expect(writes(app)).toEqual([])
})

test('both lists can expand independently and only an explicit choice loads another column', async ({page,app}) => {
    fixture(app)
    app.on('GET', path + '/subjects', ({query}) => {
        const kind = query.get('kind')
        const pageNo = Number(query.get('page'))
        const size = pageNo === 1 ? 5 : 1
        return {body:{subjects:Array.from({length:size}, (_, i) => {
            const id = kind + '-' + pageNo + '-' + i
            return {id,displayName:id}
        }),hasMore:pageNo === 1}}
    })
    await app.open('/admin#mcp-tool-permissions')
    await page.getByRole('button',{name:'Choose comparison for group-1-0'}).click()
    const groups = page.getByRole('dialog').getByRole('group',{name:'Groups'})
    const people = page.getByRole('dialog').getByRole('group',{name:'People'})
    await people.getByRole('button',{name:'Show more'}).click()
    await expect(people.getByRole('button',{name:'user-1-4'})).toBeFocused()
    await people.getByRole('button',{name:'Show more'}).click()
    await expect(people.getByRole('button',{name:'user-2-0'})).toBeFocused()
    await expect(groups.getByRole('button',{name:'group-1-4'})).toHaveCount(0)
    await people.getByRole('button',{name:'user-2-0'}).click()
    await expect(page.getByRole('button',{name:'Choose comparison for user-2-0'})).toBeVisible()
    expect(writes(app)).toEqual([])
})

-- NDS // ANON PANEL v5 | LocalScript / Executor
-- Flaw-audited. All 23 issues from the v4 audit addressed.
-- Client-side only. Read before you run.

local Players = game:GetService("Players")
local RunService = game:GetService("RunService")
local UserInputService = game:GetService("UserInputService")
local Workspace = game:GetService("Workspace")
local Lighting = game:GetService("Lighting")
local HttpService = game:GetService("HttpService")
local VirtualUser = game:GetService("VirtualUser")

local lp = Players.LocalPlayer

-- ============================================================
-- CONFIG
-- ============================================================
local DEFAULT_CONFIG = {
    GodMode = false,
    AntiFling = false,
    Fly = false,
    WalkOnWater = false,
    Noclip = false,
    Freeze = false,
    Fullbright = false,
    NoFog = false,
    InfiniteJump = false,
    AntiAFK = false,
    ESP = false,
    AutoSurvive = false,
    TrollTouchFling = false,
    TrollSpinAura = false,
    TrollGravityField = false,
    TrollOrbit = false,
    ChaosMeteorRain = false,
    ChaosRandomExplosion = false,
    ChaosPartStorm = false,
    FlySpeed = 70,
    WalkSpeed = 16,
    JumpPower = 50,
    Gravity = 196.2,
    FOV = 70,
    ESPDistance = 500,
    SpinAuraRadius = 15,
    SpinAuraForce = 200,
    GravityFieldRadius = 25,
    GravityFieldForce = 300,
    ChaosInterval = 1.5,
    AutoSurviveDelay = 0.5,
    AutoSurviveCooldown = 2,
}

local CONFIG_FILE = "anon_nds_config.json"

local function loadConfig()
    local ok, data = pcall(function()
        return HttpService:JSONDecode(readfile(CONFIG_FILE))
    end)
    if ok and type(data) == "table" then
        local merged = {}
        for k, v in pairs(DEFAULT_CONFIG) do
            merged[k] = data[k] ~= nil and data[k] or v
        end
        -- force all toggles off on load
        for k, v in pairs(merged) do
            if type(v) == "boolean" then merged[k] = false end
        end
        return merged
    end
    return table.clone(DEFAULT_CONFIG)
end

local function saveConfig()
    pcall(function()
        local toSave = {}
        for k, v in pairs(Config) do
            toSave[k] = v
        end
        writefile(CONFIG_FILE, HttpService:JSONEncode(toSave))
    end)
end

local Config = loadConfig()

-- ============================================================
-- STATE
-- ============================================================
local Connections = {}
local Threads = {}
local FlyBodyVelocity = nil
local FreezeAnchor = nil
local LastRootPosition = nil
local WaterPlatform = nil
local ESPBillboards = {}
local PanelVisible = true
local FPSValue = 0
local FrameCount = 0
local FPSClock = 0
local OriginalGravity = Workspace.Gravity
local OriginalFOV = Workspace.CurrentCamera and Workspace.CurrentCamera.FieldOfView or 70
local SpinAuraParts = {}
local OrbitParts = {}
local ChaosMeteors = {}
local ChaosStormParts = {}
local LastDisaster = ""
local LastAutoTp = 0
local DisasterGuiConn = nil
local DisasterLabel = nil
local Alive = true

local function track(conn)
    table.insert(Connections, conn)
    return conn
end

local function safeDisconnect(conn)
    if conn and typeof(conn) == "RBXScriptConnection" and conn.Connected then
        pcall(function() conn:Disconnect() end)
    end
end

local function clearTracked()
    for _, c in ipairs(Connections) do
        safeDisconnect(c)
    end
    Connections = {}
end

local function startThread(name, fn)
    if Threads[name] then return end
    Threads[name] = task.spawn(function()
        local ok, err = pcall(fn)
        if not ok then
            warn("[ANON] thread '" .. name .. "' crashed: " .. tostring(err))
        end
        Threads[name] = nil
    end)
end

local function stopThread(name)
    Threads[name] = nil
end

-- ============================================================
-- CHARACTER HELPERS
-- ============================================================
local function getChar() return lp.Character end
local function getRoot()
    local char = getChar()
    return char and char:FindFirstChild("HumanoidRootPart")
end
local function getHumanoid()
    local char = getChar()
    return char and char:FindFirstChildOfClass("Humanoid")
end

-- ============================================================
-- DISASTER DETECTION (narrow scan)
-- Hooks PlayerGui.ChildAdded, reads new labels once.
-- ============================================================
local DISASTER_KEYWORDS = {
    earthquake = "earthquake", tsunami = "tsunami",
    ["flash flood"] = "flashflood", flood = "flashflood",
    tornado = "tornado", blizzard = "blizzard",
    ["acid rain"] = "acidrain", meteor = "meteor",
    volcano = "volcano", volcanic = "volcano",
    thunder = "thunder", lightning = "thunder",
    fire = "fire", sandstorm = "sandstorm",
    avalanche = "avalanche", virus = "virus",
    hurricane = "tornado",
}

local function matchDisaster(text)
    if not text or #text < 3 then return nil end
    local lower = text:lower()
    for kw, id in pairs(DISASTER_KEYWORDS) do
        if lower:find(kw, 1, true) then
            return id
        end
    end
    return nil
end

local function scanLabel(label)
    if not (label:IsA("TextLabel") or label:IsA("TextButton")) then return end
    local id = matchDisaster(label.Text)
    if id and label:IsDescendantOf(lp:FindFirstChild("PlayerGui") or Workspace) then
        DisasterLabel = id
        LastDisaster = id
    end
end

local function hookDisasterDetection()
    local pg = lp:FindFirstChild("PlayerGui")
    if not pg then return end

    DisasterGuiConn = pg.DescendantAdded:Connect(function(desc)
        scanLabel(desc)
    end)
end

local function getCurrentDisaster()
    return DisasterLabel or ""
end

-- ============================================================
-- AUTO SURVIVE
-- ============================================================
local function findSafePosition(disaster)
    local root = getRoot()
    if not root then return nil end
    local origin = root.Position
    local char = getChar()

    local indoorDisasters = { acidrain = true, blizzard = true, meteor = true, thunder = true, sandstorm = true }
    local highDisasters = { tsunami = true, flashflood = true }
    local outdoorDisasters = { earthquake = true, fire = true, tornado = true, volcano = true, avalanche = true, virus = true }

    if indoorDisasters[disaster] then
        -- multi-ray roof check
        local rayParams = RaycastParams.new()
        rayParams.FilterType = Enum.RaycastFilterType.Exclude
        rayParams.FilterDescendantsInstances = { char }
        local hits = 0
        local dirs = {
            Vector3.new(0, 1, 0),
            Vector3.new(0.3, 1, 0).Unit,
            Vector3.new(-0.3, 1, 0).Unit,
            Vector3.new(0, 1, 0.3).Unit,
            Vector3.new(0, 1, -0.3).Unit,
        }
        local closestHit = nil
        for _, dir in ipairs(dirs) do
            local r = Workspace:Raycast(origin, dir * 50, rayParams)
            if r then
                hits += 1
                if not closestHit or (r.Position - origin).Magnitude < (closestHit.Position - origin).Magnitude then
                    closestHit = r
                end
            end
        end
        if hits >= 3 and closestHit then
            return closestHit.Position - Vector3.new(0, 3, 0)
        end
        return origin

    elseif highDisasters[disaster] then
        -- bounded scan: parts within 150 studs, anchored, above
        local params = OverlapParams.new()
        params.FilterType = Enum.RaycastFilterType.Exclude
        params.FilterDescendantsInstances = { char }
        local parts = Workspace:GetPartBoundsInRadius(origin, 150, params)
        local bestY, bestPos = origin.Y, origin
        for _, part in ipairs(parts) do
            if part.Anchored and part.Position.Y > bestY then
                bestY = part.Position.Y
                bestPos = part.Position + Vector3.new(0, 5, 0)
            end
        end
        return bestPos

    elseif outdoorDisasters[disaster] then
        local rayParams = RaycastParams.new()
        rayParams.FilterType = Enum.RaycastFilterType.Exclude
        rayParams.FilterDescendantsInstances = { char }
        local dirs = {
            Vector3.new(20, 0, 0), Vector3.new(-20, 0, 0),
            Vector3.new(0, 0, 20), Vector3.new(0, 0, -20),
        }
        local best, bestScore = origin, 0
        for _, dir in ipairs(dirs) do
            local hit = Workspace:Raycast(origin, dir, rayParams)
            local dist = hit and (hit.Position - origin).Magnitude or dir.Magnitude
            if dist > bestScore then
                bestScore = dist
                best = origin + dir.Unit * (dist - 2)
            end
        end
        return best
    end
    return origin
end

local function autoSurviveLoop()
    while Config.AutoSurvive and Alive do
        local disaster = getCurrentDisaster()
        if disaster ~= "" and (disaster ~= LastDisaster or tick() - LastAutoTp > Config.AutoSurviveCooldown) then
            if tick() - LastAutoTp >= Config.AutoSurviveCooldown then
                local root = getRoot()
                if root then
                    local ok, safe = pcall(findSafePosition, disaster)
                    if ok and safe then
                        pcall(function() root.CFrame = CFrame.new(safe) end)
                        LastAutoTp = tick()
                        LastDisaster = disaster
                    end
                end
            end
        end
        task.wait(Config.AutoSurviveDelay)
    end
end

local function startAutoSurvive()
    startThread("AutoSurvive", autoSurviveLoop)
end

local function stopAutoSurvive()
    stopThread("AutoSurvive")
    LastDisaster = ""
    LastAutoTp = 0
end

-- ============================================================
-- GOD MODE
-- ============================================================
local function enableGodMode()
    local hum = getHumanoid()
    if not hum then return end
    hum.MaxHealth = math.huge
    hum.Health = math.huge
    hum.BreakJointsOnDeath = false
    pcall(function() hum:SetStateEnabled(Enum.HumanoidStateType.Dead, false) end)
end

local function disableGodMode()
    local hum = getHumanoid()
    if not hum then return end
    hum.MaxHealth = 100
    hum.Health = 100
    hum.BreakJointsOnDeath = true
    pcall(function() hum:SetStateEnabled(Enum.HumanoidStateType.Dead, true) end)
end

-- ============================================================
-- ANTI-FLING (fixed purge)
-- ============================================================
local function purgeForeignHandles()
    for _, player in ipairs(Players:GetPlayers()) do
        if player ~= lp and player.Character then
            for _, desc in ipairs(player.Character:GetDescendants()) do
                if desc:IsA("Accessory") then
                    local handle = desc:FindFirstChild("Handle")
                    if handle and handle:IsA("BasePart") and handle.CanCollide then
                        handle.CanCollide = false
                    end
                end
            end
        end
    end
end

local function antiFlingLoop()
    if not Config.AntiFling then return end
    local root = getRoot()
    if not root then return end
    if root.AssemblyAngularVelocity.Magnitude > 40 then
        root.AssemblyAngularVelocity = Vector3.zero
    end
    if root.AssemblyLinearVelocity.Magnitude > 150 then
        root.AssemblyLinearVelocity = Vector3.zero
    end
    if LastRootPosition then
        local delta = (root.Position - LastRootPosition).Magnitude
        if delta > 30 then
            root.CFrame = CFrame.new(LastRootPosition)
            root.AssemblyLinearVelocity = Vector3.zero
            root.AssemblyAngularVelocity = Vector3.zero
        end
    end
    LastRootPosition = root.Position
end

-- ============================================================
-- SPEED / JUMP / GRAVITY / FOV
-- ============================================================
local function applySpeed(v)
    Config.WalkSpeed = v
    local hum = getHumanoid()
    if hum then hum.WalkSpeed = v end
end

local function applyJump(v)
    Config.JumpPower = v
    local hum = getHumanoid()
    if hum then hum.JumpPower = v; hum.UseJumpPower = true end
end

local function applyGravity(v)
    Config.Gravity = v
    Workspace.Gravity = v
end

local function applyFOV(v)
    Config.FOV = v
    local cam = Workspace.CurrentCamera
    if cam then cam.FieldOfView = v end
end

-- ============================================================
-- FLY
-- ============================================================
local function enableFly()
    local root = getRoot()
    if not root then return end
    if FlyBodyVelocity then FlyBodyVelocity:Destroy() end
    FlyBodyVelocity = Instance.new("BodyVelocity")
    FlyBodyVelocity.MaxForce = Vector3.new(4000, 4000, 4000)
    FlyBodyVelocity.Velocity = Vector3.zero
    FlyBodyVelocity.Parent = root
end

local function disableFly()
    if FlyBodyVelocity then FlyBodyVelocity:Destroy(); FlyBodyVelocity = nil end
end

local function flyLoop()
    if not Config.Fly or not FlyBodyVelocity then return end
    if not FlyBodyVelocity.Parent then disableFly(); return end
    local cam = Workspace.CurrentCamera
    if not cam then return end
    local move = Vector3.zero
    if UserInputService:IsKeyDown(Enum.KeyCode.W) then move += cam.CFrame.LookVector end
    if UserInputService:IsKeyDown(Enum.KeyCode.S) then move -= cam.CFrame.LookVector end
    if UserInputService:IsKeyDown(Enum.KeyCode.A) then move -= cam.CFrame.RightVector end
    if UserInputService:IsKeyDown(Enum.KeyCode.D) then move += cam.CFrame.RightVector end
    if UserInputService:IsKeyDown(Enum.KeyCode.Space) then move += Vector3.new(0,1,0) end
    if UserInputService:IsKeyDown(Enum.KeyCode.LeftControl) then move -= Vector3.new(0,1,0) end
    FlyBodyVelocity.Velocity = move.Magnitude > 0 and (move.Unit * Config.FlySpeed) or Vector3.zero
end

-- ============================================================
-- WALK ON WATER (current API)
-- ============================================================
local function waterLoop()
    if not Config.WalkOnWater then
        if WaterPlatform and WaterPlatform.Parent then WaterPlatform.Parent = nil end
        return
    end
    local root = getRoot()
    if not root then return end
    local pos = root.Position + Vector3.new(0, -3, 0)
    local params = OverlapParams.new()
    params.FilterType = Enum.RaycastFilterType.Exclude
    params.FilterDescendantsInstances = { getChar() }
    local parts = Workspace:GetPartBoundsInBox(CFrame.new(pos), Vector3.new(4, 4, 4), params)
    local overWater = false
    for _, part in ipairs(parts) do
        if part.Material == Enum.Material.Water or part.Name:lower():find("water", 1, true) then
            overWater = true; break
        end
    end
    if overWater then
        if not WaterPlatform then
            WaterPlatform = Instance.new("Part")
            WaterPlatform.Anchored = true
            WaterPlatform.CanCollide = true
            WaterPlatform.Size = Vector3.new(6,1,6)
            WaterPlatform.Transparency = 1
            WaterPlatform.Massless = true
        end
        WaterPlatform.Position = Vector3.new(root.Position.X, root.Position.Y-3.5, root.Position.Z)
        WaterPlatform.Parent = Workspace
    elseif WaterPlatform then
        WaterPlatform.Parent = nil
    end
end

-- ============================================================
-- NOCLIP (event-driven, not per-frame)
-- ============================================================
local noclipConn = nil

local function noclipHandler(part)
    if Config.Noclip and part:IsA("BasePart") then
        part.CanCollide = false
    end
end

local function enableNoclip()
    local char = getChar()
    if not char then return end
    for _, part in ipairs(char:GetDescendants()) do
        if part:IsA("BasePart") then part.CanCollide = false end
    end
    if noclipConn then noclipConn:Disconnect() end
    noclipConn = char.DescendantAdded:Connect(noclipHandler)
end

local function disableNoclip()
    if noclipConn then noclipConn:Disconnect(); noclipConn = nil end
    local char = getChar()
    if not char then return end
    for _, part in ipairs(char:GetDescendants()) do
        if part:IsA("BasePart") and part.Name ~= "HumanoidRootPart" then
            part.CanCollide = true
        end
    end
end

-- ============================================================
-- FREEZE
-- ============================================================
local function enableFreeze()
    local root = getRoot()
    if not root then return end
    if FreezeAnchor then FreezeAnchor:Destroy() end
    FreezeAnchor = Instance.new("BodyPosition")
    FreezeAnchor.MaxForce = Vector3.new(math.huge, math.huge, math.huge)
    FreezeAnchor.Position = root.Position
    FreezeAnchor.Parent = root
end

local function disableFreeze()
    if FreezeAnchor then FreezeAnchor:Destroy(); FreezeAnchor = nil end
end

-- ============================================================
-- FULLBRIGHT / NO FOG
-- ============================================================
local savedLighting = {}
local FullbrightInstances = {}

local function enableFullbright()
    if #FullbrightInstances > 0 then return end
    savedLighting = {
        Brightness = Lighting.Brightness, Ambient = Lighting.Ambient,
        OutdoorAmbient = Lighting.OutdoorAmbient, ClockTime = Lighting.ClockTime,
        GlobalShadows = Lighting.GlobalShadows,
    }
    Lighting.Brightness = 2
    Lighting.Ambient = Color3.fromRGB(180,180,180)
    Lighting.OutdoorAmbient = Color3.fromRGB(180,180,180)
    Lighting.ClockTime = 12
    Lighting.GlobalShadows = false
    local fb = Instance.new("ColorCorrectionEffect")
    fb.Brightness = 0.2; fb.Contrast = 0.1
    fb.Parent = Lighting
    table.insert(FullbrightInstances, fb)
end

local function disableFullbright()
    if savedLighting.Brightness then
        Lighting.Brightness = savedLighting.Brightness
        Lighting.Ambient = savedLighting.Ambient
        Lighting.OutdoorAmbient = savedLighting.OutdoorAmbient
        Lighting.ClockTime = savedLighting.ClockTime
        Lighting.GlobalShadows = savedLighting.GlobalShadows
    end
    for _, inst in ipairs(FullbrightInstances) do
        if inst then inst:Destroy() end
    end
    FullbrightInstances = {}
end

local savedFog = {}
local FogInstance = nil

local function enableNoFog()
    if FogInstance then return end
    savedFog = { FogEnd = Lighting.FogEnd, FogStart = Lighting.FogStart, FogColor = Lighting.FogColor }
    Lighting.FogEnd = 100000
    Lighting.FogStart = 100000
    FogInstance = Instance.new("Atmosphere")
    FogInstance.Density = 0
    FogInstance.Parent = Lighting
end

local function disableNoFog()
    if savedFog.FogEnd then
        Lighting.FogEnd = savedFog.FogEnd
        Lighting.FogStart = savedFog.FogStart
        Lighting.FogColor = savedFog.FogColor
    end
    if FogInstance then FogInstance:Destroy(); FogInstance = nil end
end

-- ============================================================
-- INFINITE JUMP
-- ============================================================
local function jumpHook()
    if not Config.InfiniteJump then return end
    local hum = getHumanoid()
    if not hum then return end
    if hum:GetState() == Enum.HumanoidStateType.Freefall then
        hum:ChangeState(Enum.HumanoidStateType.Jumping)
    end
end

-- ============================================================
-- ANTI-AFK (real implementation)
-- ============================================================
local lastAntiAfk = 0

local function antiAfkLoop(dt)
    if not Config.AntiAFK then return end
    local now = tick()
    if now - lastAntiAfk < 60 then return end
    lastAntiAfk = now
    pcall(function()
        VirtualUser:CaptureController()
        VirtualUser:ClickButton2(Vector2.new(math.random(0, 100), math.random(0, 100)))
    end)
end

-- ============================================================
-- PLAYER ESP (respawn-safe)
-- ============================================================
local function createESPBillboard(player)
    if player == lp then return end
    if ESPBillboards[player] then
        if ESPBillboards[player].Parent then return end
        ESPBillboards[player]:Destroy()
        ESPBillboards[player] = nil
    end
    local char = player.Character
    if not char then return end
    local head = char:FindFirstChild("Head")
    if not head then return end
    local bb = Instance.new("BillboardGui")
    bb.Size = UDim2.new(0, 120, 0, 24)
    bb.StudsOffset = Vector3.new(0, 2.5, 0)
    bb.AlwaysOnTop = true
    bb.Adornee = head
    bb.Parent = head
    local lbl = Instance.new("TextLabel")
    lbl.Size = UDim2.new(1,0,1,0)
    lbl.BackgroundTransparency = 1
    lbl.TextColor3 = Color3.fromRGB(139, 92, 246)
    lbl.TextStrokeTransparency = 0
    lbl.TextSize = 12
    lbl.Font = Enum.Font.GothamBold
    lbl.Text = player.Name
    lbl.Parent = bb
    ESPBillboards[player] = bb
end

local function removeESPBillboard(player)
    local bb = ESPBillboards[player]
    if bb then bb:Destroy(); ESPBillboards[player] = nil end
end

local function updateESPDistance()
    if not Config.ESP then return end
    local myRoot = getRoot()
    if not myRoot then return end
    for player, bb in pairs(ESPBillboards) do
        if bb and bb.Parent then
            local char = player.Character
            local head = char and char:FindFirstChild("Head")
            if head then
                local dist = (head.Position - myRoot.Position).Magnitude
                local lbl = bb:FindFirstChildOfClass("TextLabel")
                if lbl then
                    if dist <= Config.ESPDistance then
                        bb.Enabled = true
                        lbl.Text = string.format("%s [%d]", player.Name, math.floor(dist))
                    else
                        bb.Enabled = false
                    end
                end
            else
                removeESPBillboard(player)
            end
        elseif bb then
            removeESPBillboard(player)
        end
    end
end

local function refreshESPAll()
    for _, player in ipairs(Players:GetPlayers()) do createESPBillboard(player) end
end

local function clearESP()
    for player, bb in pairs(ESPBillboards) do if bb then bb:Destroy() end end
    ESPBillboards = {}
end

track(Players.PlayerAdded:Connect(function(p)
    p.CharacterAdded:Connect(function()
        if Config.ESP then
            task.wait(0.5)
            createESPBillboard(p)
        end
    end)
end))

track(Players.PlayerRemoving:Connect(function(p)
    removeESPBillboard(p)
end))

-- ============================================================
-- TROLL // TOUCH FLING
-- ============================================================
local function touchFlingLoop()
    while Config.TrollTouchFling do
        local myChar = getChar()
        if myChar then
            local myRoot = myChar:FindFirstChild("HumanoidRootPart")
            if myRoot then
                for _, player in ipairs(Players:GetPlayers()) do
                    if player ~= lp then
                        local theirRoot = player.Character and player.Character:FindFirstChild("HumanoidRootPart")
                        if theirRoot then
                            local dist = (theirRoot.Position - myRoot.Position).Magnitude
                            if dist < 6 then
                                local dir = (theirRoot.Position - myRoot.Position).Unit
                                local bv = Instance.new("BodyVelocity")
                                bv.Velocity = (dir + Vector3.new(0,1,0)) * 500
                                bv.MaxForce = Vector3.new(1e6,1e6,1e6)
                                bv.Parent = theirRoot
                                task.delay(0.15, function() if bv then bv:Destroy() end end)
                            end
                        end
                    end
                end
            end
        end
        task.wait(0.1)
    end
end

-- ============================================================
-- TROLL // SPIN AURA (bounded, cleared on destroy)
-- ============================================================
local spinAuraAngle = 0

local function createSpinAuraParts()
    for i = 1, 12 do
        local part = Instance.new("Part")
        part.Size = Vector3.new(2,2,2)
        part.Material = Enum.Material.Neon
        part.Color = Color3.fromRGB(139, 92, 246)
        part.Anchored = false
        part.CanCollide = true
        part.Transparency = 0.4
        part.Massless = true
        part.Parent = Workspace
        table.insert(SpinAuraParts, part)
    end
end

local function destroySpinAuraParts()
    for _, part in ipairs(SpinAuraParts) do
        if part and part.Parent then part:Destroy() end
    end
    SpinAuraParts = {}
end

local function spinAuraLoop(dt)
    if not Config.TrollSpinAura then
        if #SpinAuraParts > 0 then destroySpinAuraParts() end
        return
    end
    local root = getRoot()
    if not root then return end
    if #SpinAuraParts == 0 then createSpinAuraParts() end
    spinAuraAngle += dt * 3
    for i, part in ipairs(SpinAuraParts) do
        if part and part.Parent then
            local angle = spinAuraAngle + (i / #SpinAuraParts) * math.pi * 2
            local offset = Vector3.new(math.cos(angle) * Config.SpinAuraRadius, 1, math.sin(angle) * Config.SpinAuraRadius)
            part.CFrame = CFrame.new(root.Position + offset)
            part.AssemblyLinearVelocity = (root.Position + offset - part.Position).Unit * Config.SpinAuraForce
        end
    end
end

-- ============================================================
-- TROLL // GRAVITY FIELD (10 Hz, radius-scoped)
-- ============================================================
local gravityFieldAccum = 0
local GRAVITY_FIELD_HZ = 10

local function gravityFieldStep()
    local root = getRoot()
    if not root then return end
    local params = OverlapParams.new()
    params.FilterType = Enum.RaycastFilterType.Exclude
    params.FilterDescendantsInstances = { getChar() }
    local parts = Workspace:GetPartBoundsInRadius(root.Position, Config.GravityFieldRadius, params)
    for _, obj in ipairs(parts) do
        if obj:IsA("BasePart") and not obj.Anchored and not obj:IsDescendantOf(getChar()) then
            local dist = (obj.Position - root.Position).Magnitude
            if dist > 1 then
                local dir = (root.Position - obj.Position).Unit
                obj.AssemblyLinearVelocity = dir * Config.GravityFieldForce
            end
        end
    end
end

local function gravityFieldLoop(dt)
    if not Config.TrollGravityField then return end
    gravityFieldAccum += dt
    if gravityFieldAccum < 1 / GRAVITY_FIELD_HZ then return end
    gravityFieldAccum = 0
    pcall(gravityFieldStep)
end

-- ============================================================
-- TROLL // ORBIT
-- ============================================================
local orbitAngle = 0

local function createOrbitParts()
    for i = 1, 20 do
        local part = Instance.new("Part")
        part.Size = Vector3.new(0.8, 0.8, 0.8)
        part.Material = Enum.Material.Neon
        part.Color = Color3.fromRGB(245, 158, 11)
        part.Anchored = true
        part.CanCollide = false
        part.Transparency = 0.3
        part.Parent = Workspace
        table.insert(OrbitParts, part)
    end
end

local function destroyOrbitParts()
    for _, p in ipairs(OrbitParts) do if p and p.Parent then p:Destroy() end end
    OrbitParts = {}
end

local function orbitLoop(dt)
    if not Config.TrollOrbit then
        if #OrbitParts > 0 then destroyOrbitParts() end
        return
    end
    local root = getRoot()
    if not root then return end
    if #OrbitParts == 0 then createOrbitParts() end
    orbitAngle += dt * 2
    for i, part in ipairs(OrbitParts) do
        if part and part.Parent then
            local a = orbitAngle + (i / #OrbitParts) * math.pi * 2
            local r = 8 + math.sin(orbitAngle * 2 + i) * 3
            part.CFrame = CFrame.new(root.Position + Vector3.new(math.cos(a) * r, math.sin(a * 3) * 2 + 3, math.sin(a) * r))
        end
    end
end

-- ============================================================
-- CHAOS // METEOR RAIN (thread-guarded, bounded)
-- ============================================================
local MAX_CHAOS_PARTS = 40

local function chaosMeteorLoop()
    while Config.ChaosMeteorRain do
        local root = getRoot()
        if root and #ChaosMeteors < MAX_CHAOS_PARTS then
            for i = 1, 3 do
                if #ChaosMeteors >= MAX_CHAOS_PARTS then break end
                local meteor = Instance.new("Part")
                meteor.Size = Vector3.new(6,6,6)
                meteor.Material = Enum.Material.Neon
                meteor.Color = Color3.fromRGB(255, 80, 0)
                meteor.Anchored = true
                meteor.CanCollide = false
                meteor.Position = root.Position + Vector3.new(math.random(-60, 60), 80, math.random(-60, 60))
                meteor.Parent = Workspace
                table.insert(ChaosMeteors, meteor)

                task.spawn(function()
                    for _ = 1, 60 do
                        if not meteor or not meteor.Parent then break end
                        meteor.Position -= Vector3.new(0, 2, 0)
                        task.wait(0.02)
                    end
                    if meteor and meteor.Parent then
                        local explosion = Instance.new("Explosion")
                        explosion.Position = meteor.Position
                        explosion.BlastRadius = 12
                        explosion.BlastPressure = 50000
                        explosion.Parent = Workspace
                        meteor:Destroy()
                    end
                    for idx, m in ipairs(ChaosMeteors) do
                        if m == meteor then table.remove(ChaosMeteors, idx); break end
                    end
                end)
            end
        end
        task.wait(Config.ChaosInterval)
    end
end

-- ============================================================
-- CHAOS // RANDOM EXPLOSION (thread-guarded)
-- ============================================================
local function chaosExplosionLoop()
    while Config.ChaosRandomExplosion do
        local root = getRoot()
        if root then
            local explosion = Instance.new("Explosion")
            explosion.Position = root.Position + Vector3.new(math.random(-50,50), 5, math.random(-50,50))
            explosion.BlastRadius = 15
            explosion.BlastPressure = 100000
            explosion.Parent = Workspace
        end
        task.wait(Config.ChaosInterval)
    end
end

-- ============================================================
-- CHAOS // PART STORM (thread-guarded, bounded)
-- ============================================================
local function chaosStormLoop()
    while Config.ChaosPartStorm do
        local root = getRoot()
        if root and #ChaosStormParts < MAX_CHAOS_PARTS then
            for i = 1, 5 do
                if #ChaosStormParts >= MAX_CHAOS_PARTS then break end
                local part = Instance.new("Part")
                part.Size = Vector3.new(math.random(1,4), math.random(1,4), math.random(1,4))
                part.Material = Enum.Material.Neon
                part.Color = Color3.fromRGB(math.random(100,255), math.random(50,200), math.random(50,200))
                part.Anchored = false
                part.CanCollide = true
                part.Position = root.Position + Vector3.new(math.random(-30,30), 15, math.random(-30,30))
                part.Parent = Workspace
                part.AssemblyLinearVelocity = Vector3.new(math.random(-80,80), math.random(-20,20), math.random(-80,80))
                table.insert(ChaosStormParts, part)
                task.delay(5, function()
                    if part and part.Parent then part:Destroy() end
                    for idx, s in ipairs(ChaosStormParts) do
                        if s == part then table.remove(ChaosStormParts, idx); break end
                    end
                end)
            end
        end
        task.wait(Config.ChaosInterval)
    end
end

-- ============================================================
-- FPS
-- ============================================================
local function fpsLoop(dt)
    FrameCount += 1
    FPSClock += dt
    if FPSClock >= 1 then
        FPSValue = math.floor(FrameCount / FPSClock + 0.5)
        FrameCount = 0
        FPSClock = 0
    end
end

-- ============================================================
-- MAIN HEARTBEAT (pcall-wrapped, split load)
-- ============================================================
local mainHeartbeat = RunService.Heartbeat:Connect(function(dt)
    local ok, err = pcall(function()
        if Config.GodMode then enableGodMode() end
        antiFlingLoop()
        flyLoop()
        waterLoop()
        jumpHook()
        updateESPDistance()
        spinAuraLoop(dt)
        gravityFieldLoop(dt)
        orbitLoop(dt)
        antiAfkLoop(dt)
        fpsLoop(dt)
    end)
    if not ok then
        warn("[ANON] heartbeat error: " .. tostring(err))
    end
end)

-- ============================================================
-- RESPAWN
-- ============================================================
local function onCharacterAdded()
    Alive = true
    task.wait(0.5)
    if not getChar() then return end
    applySpeed(Config.WalkSpeed)
    applyJump(Config.JumpPower)
    if Config.GodMode then enableGodMode() end
    if Config.Fly then enableFly() end
    if Config.Noclip then enableNoclip() end
    if Config.ESP then refreshESPAll() end
    if Config.AutoSurvive then startAutoSurvive() end
end

track(lp.CharacterAdded:Connect(onCharacterAdded))
track(lp.CharacterRemoving:Connect(function()
    Alive = false
    stopAutoSurvive()
    disableFly()
    disableFreeze()
    if noclipConn then noclipConn:Disconnect(); noclipConn = nil end
end))

task.spawn(function()
    hookDisasterDetection()
    if lp.Character then onCharacterAdded() end
end)

-- ============================================================
-- UI
-- ============================================================
local Palette = {
    Bg = Color3.fromRGB(10,10,11),
    Surface = Color3.fromRGB(18,18,20),
    SurfaceAlt = Color3.fromRGB(24,24,27),
    Border = Color3.fromRGB(39,39,42),
    Text = Color3.fromRGB(244,244,245),
    TextMuted = Color3.fromRGB(161,161,170),
    Accent = Color3.fromRGB(139,92,246),
    Green = Color3.fromRGB(34,197,94),
    Red = Color3.fromRGB(239,68,68),
    Amber = Color3.fromRGB(245,158,11),
    Cyan = Color3.fromRGB(34,211,238),
}

local ScreenGui = Instance.new("ScreenGui")
ScreenGui.Name = "ANON_NDS_V5"
ScreenGui.ResetOnSpawn = false
ScreenGui.DisplayOrder = 999
ScreenGui.Parent = lp:WaitForChild("PlayerGui")

local Root = Instance.new("Frame")
Root.Size = UDim2.new(0, 320, 0, 560)
Root.Position = UDim2.new(0, 40, 0, 40)
Root.BackgroundColor3 = Palette.Bg
Root.BorderSizePixel = 0
Root.Active = true
Root.Parent = ScreenGui

local RootCorner = Instance.new("UICorner")
RootCorner.CornerRadius = UDim.new(0, 12)
RootCorner.Parent = Root

local RootStroke = Instance.new("UIStroke")
RootStroke.Color = Palette.Border
RootStroke.Thickness = 1
RootStroke.Parent = Root

-- Drag only from header (fixes scroll conflict)
local Header = Instance.new("Frame")
Header.Size = UDim2.new(1,0,0,52)
Header.BackgroundTransparency = 1
Header.Active = true
Header.Parent = Root

local dragging, dragStart, startPos
Header.InputBegan:Connect(function(input)
    if input.UserInputType == Enum.UserInputType.MouseButton1 or input.UserInputType == Enum.UserInputType.Touch then
        dragging = true; dragStart = input.Position; startPos = Root.Position
        input.Changed:Connect(function()
            if input.UserInputState == Enum.UserInputState.End then dragging = false end
        end)
    end
end)
UserInputService.InputChanged:Connect(function(input)
    if dragging and (input.UserInputType == Enum.UserInputType.MouseMovement or input.UserInputType == Enum.UserInputType.Touch) then
        local delta = input.Position - dragStart
        Root.Position = UDim2.new(startPos.X.Scale, startPos.X.Offset + delta.X, startPos.Y.Scale, startPos.Y.Offset + delta.Y)
    end
end)

local Title = Instance.new("TextLabel")
Title.Size = UDim2.new(1,-100,0,22)
Title.Position = UDim2.new(0,16,0,12)
Title.BackgroundTransparency = 1
Title.Text = "NDS // ANON"
Title.TextColor3 = Palette.Text
Title.TextSize = 15
Title.Font = Enum.Font.GothamBold
Title.TextXAlignment = Enum.TextXAlignment.Left
Title.Parent = Header

local Subtitle = Instance.new("TextLabel")
Subtitle.Size = UDim2.new(1,-100,0,16)
Subtitle.Position = UDim2.new(0,16,0,30)
Subtitle.BackgroundTransparency = 1
Subtitle.Text = "v5 · audited build"
Subtitle.TextColor3 = Palette.Cyan
Subtitle.TextSize = 11
Subtitle.Font = Enum.Font.Gotham
Subtitle.TextXAlignment = Enum.TextXAlignment.Left
Subtitle.Parent = Header

local FPSLabel = Instance.new("TextLabel")
FPSLabel.Size = UDim2.new(0,70,0,22)
FPSLabel.Position = UDim2.new(1,-86,0,16)
FPSLabel.BackgroundColor3 = Palette.Surface
FPSLabel.Text = "FPS 60"
FPSLabel.TextColor3 = Palette.Accent
FPSLabel.TextSize = 11
FPSLabel.Font = Enum.Font.Code
FPSLabel.Parent = Header
local FPSLabelCorner = Instance.new("UICorner")
FPSLabelCorner.CornerRadius = UDim.new(0,6)
FPSLabelCorner.Parent = FPSLabel

local Separator = Instance.new("Frame")
Separator.Size = UDim2.new(1,-32,0,1)
Separator.Position = UDim2.new(0,16,0,52)
Separator.BackgroundColor3 = Palette.Border
Separator.BorderSizePixel = 0
Separator.Parent = Root

local Scroll = Instance.new("ScrollingFrame")
Scroll.Size = UDim2.new(1,-32,1,-100)
Scroll.Position = UDim2.new(0,16,0,60)
Scroll.BackgroundTransparency = 1
Scroll.BorderSizePixel = 0
Scroll.ScrollBarThickness = 3
Scroll.ScrollBarImageColor3 = Palette.Border
Scroll.AutomaticCanvasSize = Enum.AutomaticSize.Y
Scroll.Parent = Root

local ScrollLayout = Instance.new("UIListLayout")
ScrollLayout.Padding = UDim.new(0,6)
ScrollLayout.Parent = Scroll

local function makeSectionLabel(text, color)
    local lbl = Instance.new("TextLabel")
    lbl.Size = UDim2.new(1,0,0,22)
    lbl.BackgroundTransparency = 1
    lbl.Text = text
    lbl.TextColor3 = color or Palette.TextMuted
    lbl.TextSize = 10
    lbl.Font = Enum.Font.GothamBold
    lbl.TextXAlignment = Enum.TextXAlignment.Left
    lbl.Parent = Scroll
end

local function makeToggle(label, initial, callback)
    local Btn = Instance.new("TextButton")
    Btn.Size = UDim2.new(1,0,0,36)
    Btn.BackgroundColor3 = Palette.Surface
    Btn.BorderSizePixel = 0
    Btn.Text = ""
    Btn.AutoButtonColor = false
    Btn.Parent = Scroll
    local C = Instance.new("UICorner"); C.CornerRadius = UDim.new(0,8); C.Parent = Btn
    local S = Instance.new("UIStroke"); S.Color = Palette.Border; S.Thickness = 1; S.Parent = Btn
    local L = Instance.new("TextLabel")
    L.Size = UDim2.new(1,-60,1,0)
    L.Position = UDim2.new(0,12,0,0)
    L.BackgroundTransparency = 1
    L.Text = label
    L.TextColor3 = Palette.Text
    L.TextSize = 13
    L.Font = Enum.Font.Gotham
    L.TextXAlignment = Enum.TextXAlignment.Left
    L.Parent = Btn
    local Track = Instance.new("Frame")
    Track.Size = UDim2.new(0,34,0,18)
    Track.Position = UDim2.new(1,-46,0.5,-9)
    Track.BackgroundColor3 = Palette.SurfaceAlt
    Track.BorderSizePixel = 0
    Track.Parent = Btn
    local TC = Instance.new("UICorner"); TC.CornerRadius = UDim.new(1,0); TC.Parent = Track
    local Knob = Instance.new("Frame")
    Knob.Size = UDim2.new(0,12,0,12)
    Knob.Position = UDim2.new(0,3,0.5,-6)
    Knob.BackgroundColor3 = Palette.TextMuted
    Knob.BorderSizePixel = 0
    Knob.Parent = Track
    local KC = Instance.new("UICorner"); KC.CornerRadius = UDim.new(1,0); KC.Parent = Knob
    local state = initial
    local function refresh()
        if state then
            Track.BackgroundColor3 = Palette.Accent
            Knob.Position = UDim2.new(1,-15,0.5,-6)
            Knob.BackgroundColor3 = Palette.Text
        else
            Track.BackgroundColor3 = Palette.SurfaceAlt
            Knob.Position = UDim2.new(0,3,0.5,-6)
            Knob.BackgroundColor3 = Palette.TextMuted
        end
    end
    refresh()
    Btn.MouseButton1Click:Connect(function()
        state = not state
        refresh()
        pcall(callback, state)
        saveConfig()
    end)
end

local function makeSlider(label, min, max, initial, callback)
    initial = math.clamp(initial, min, max)
    local Container = Instance.new("Frame")
    Container.Size = UDim2.new(1,0,0,52)
    Container.BackgroundColor3 = Palette.Surface
    Container.BorderSizePixel = 0
    Container.Parent = Scroll
    local CC = Instance.new("UICorner"); CC.CornerRadius = UDim.new(0,8); CC.Parent = Container
    local CS = Instance.new("UIStroke"); CS.Color = Palette.Border; CS.Thickness = 1; CS.Parent = Container
    local L = Instance.new("TextLabel")
    L.Size = UDim2.new(1,-80,0,20)
    L.Position = UDim2.new(0,12,0,6)
    L.BackgroundTransparency = 1
    L.Text = label
    L.TextColor3 = Palette.Text
    L.TextSize = 12
    L.Font = Enum.Font.Gotham
    L.TextXAlignment = Enum.TextXAlignment.Left
    L.Parent = Container
    local V = Instance.new("TextLabel")
    V.Size = UDim2.new(0,60,0,20)
    V.Position = UDim2.new(1,-72,0,6)
    V.BackgroundTransparency = 1
    V.Text = tostring(initial)
    V.TextColor3 = Palette.Accent
    V.TextSize = 12
    V.Font = Enum.Font.GothamBold
    V.TextXAlignment = Enum.TextXAlignment.Right
    V.Parent = Container
    local Track = Instance.new("Frame")
    Track.Size = UDim2.new(1,-24,0,4)
    Track.Position = UDim2.new(0,12,0,34)
    Track.BackgroundColor3 = Palette.SurfaceAlt
    Track.BorderSizePixel = 0
    Track.Parent = Container
    local TC = Instance.new("UICorner"); TC.CornerRadius = UDim.new(1,0); TC.Parent = Track
    local Fill = Instance.new("Frame")
    Fill.Size = UDim2.new((initial-min)/(max-min),0,1,0)
    Fill.BackgroundColor3 = Palette.Accent
    Fill.BorderSizePixel = 0
    Fill.Parent = Track
    local FC = Instance.new("UICorner"); FC.CornerRadius = UDim.new(1,0); FC.Parent = Fill
    local function setFromX(mx)
        local rel = math.clamp((mx - Track.AbsolutePosition.X) / Track.AbsoluteSize.X, 0, 1)
        local val = math.floor(min + (max-min) * rel + 0.5)
        V.Text = tostring(val)
        Fill.Size = UDim2.new(rel,0,1,0)
        pcall(callback, val)
        saveConfig()
    end
    Track.InputBegan:Connect(function(input)
        if input.UserInputType == Enum.UserInputType.MouseButton1 or input.UserInputType == Enum.UserInputType.Touch then
            setFromX(input.Position.X)
        end
    end)
    Track.InputChanged:Connect(function(input)
        if input.UserInputType == Enum.UserInputType.MouseMovement or input.UserInputType == Enum.UserInputType.Touch then
            if UserInputService:IsMouseButtonPressed(Enum.UserInputType.MouseButton1) or input.UserInputType == Enum.UserInputType.Touch then
                setFromX(input.Position.X)
            end
        end
    end)
end

-- BUILD UI
makeSectionLabel("AUTO FARM")

makeToggle("Auto Survive", Config.AutoSurvive, function(v)
    Config.AutoSurvive = v
    if v then startAutoSurvive() else stopAutoSurvive() end
end)

makeSectionLabel("SURVIVAL")

makeToggle("God Mode", Config.GodMode, function(v)
    Config.GodMode = v
    if v then enableGodMode() else disableGodMode() end
end)

makeToggle("Anti-Fling", Config.AntiFling, function(v) Config.AntiFling = v end)

makeToggle("Fly", Config.Fly, function(v)
    Config.Fly = v
    if v then enableFly() else disableFly() end
end)

makeToggle("Walk on Water", Config.WalkOnWater, function(v)
    Config.WalkOnWater = v
    if not v and WaterPlatform then WaterPlatform.Parent = nil end
end)

makeToggle("Noclip", Config.Noclip, function(v)
    Config.Noclip = v
    if v then enableNoclip() else disableNoclip() end
end)

makeToggle("Freeze", Config.Freeze, function(v)
    Config.Freeze = v
    if v then enableFreeze() else disableFreeze() end
end)

makeToggle("Infinite Jump", Config.InfiniteJump, function(v) Config.InfiniteJump = v end)

makeToggle("Fullbright", Config.Fullbright, function(v)
    Config.Fullbright = v
    if v then enableFullbright() else disableFullbright() end
end)

makeToggle("No Fog", Config.NoFog, function(v)
    Config.NoFog = v
    if v then enableNoFog() else disableNoFog() end
end)

makeToggle("Anti-AFK", Config.AntiAFK, function(v) Config.AntiAFK = v end)

makeToggle("Player ESP", Config.ESP, function(v)
    Config.ESP = v
    if v then refreshESPAll() else clearESP() end
end)

makeSlider("WalkSpeed", 16, 200, Config.WalkSpeed, applySpeed)
makeSlider("JumpPower", 50, 250, Config.JumpPower, applyJump)
makeSlider("FlySpeed", 20, 300, Config.FlySpeed, function(v) Config.FlySpeed = v end)
makeSlider("Gravity", 20, 500, math.floor(Config.Gravity), applyGravity)
makeSlider("FOV", 50, 120, Config.FOV, applyFOV)
makeSlider("ESP Distance", 50, 2000, Config.ESPDistance, function(v) Config.ESPDistance = v end)

makeSectionLabel("ADMIN TROLL", Palette.Amber)

makeToggle("Touch Fling", Config.TrollTouchFling, function(v)
    Config.TrollTouchFling = v
    if v then startThread("TouchFling", touchFlingLoop) else stopThread("TouchFling") end
end)

makeToggle("Spin Aura", Config.TrollSpinAura, function(v)
    Config.TrollSpinAura = v
    if not v then destroySpinAuraParts() end
end)

makeToggle("Gravity Field", Config.TrollGravityField, function(v) Config.TrollGravityField = v end)

makeToggle("Orbit Aura", Config.TrollOrbit, function(v)
    Config.TrollOrbit = v
    if not v then destroyOrbitParts() end
end)

makeSlider("Aura Radius", 8, 40, Config.SpinAuraRadius, function(v) Config.SpinAuraRadius = v end)
makeSlider("Aura Force", 50, 600, Config.SpinAuraForce, function(v) Config.SpinAuraForce = v end)
makeSlider("Field Radius", 10, 60, Config.GravityFieldRadius, function(v) Config.GravityFieldRadius = v end)
makeSlider("Field Force", 100, 800, Config.GravityFieldForce, function(v) Config.GravityFieldForce = v end)

makeSectionLabel("CHAOS", Palette.Red)

makeToggle("Meteor Rain", Config.ChaosMeteorRain, function(v)
    Config.ChaosMeteorRain = v
    if v then startThread("Meteor", chaosMeteorLoop) else stopThread("Meteor") end
end)

makeToggle("Random Explosion", Config.ChaosRandomExplosion, function(v)
    Config.ChaosRandomExplosion = v
    if v then startThread("Explosion", chaosExplosionLoop) else stopThread("Explosion") end
end)

makeToggle("Part Storm", Config.ChaosPartStorm, function(v)
    Config.ChaosPartStorm = v
    if v then startThread("Storm", chaosStormLoop) else stopThread("Storm") end
end)

makeSlider("Chaos Interval x10", 5, 50, math.floor(Config.ChaosInterval * 10), function(v)
    Config.ChaosInterval = v / 10
end)

local Footer = Instance.new("TextLabel")
Footer.Size = UDim2.new(1,-32,0,24)
Footer.Position = UDim2.new(0,16,1,-28)
Footer.BackgroundTransparency = 1
Footer.Text = "RightShift · config saves to " .. CONFIG_FILE
Footer.TextColor3 = Palette.TextMuted
Footer.TextSize = 10
Footer.Font = Enum.Font.Gotham
Footer.TextXAlignment = Enum.TextXAlignment.Right
Footer.Parent = Root

RunService.Heartbeat:Connect(function()
    FPSLabel.Text = "FPS " .. tostring(FPSValue)
    FPSLabel.TextColor3 = FPSValue >= 50 and Palette.Green or FPSValue >= 30 and Palette.Text or Palette.Red
end)

UserInputService.InputBegan:Connect(function(input, processed)
    if processed then return end
    if input.KeyCode == Enum.KeyCode.RightShift then
        PanelVisible = not PanelVisible
        Root.Visible = PanelVisible
    end
end)

-- ============================================================
-- CLEANUP
-- ============================================================
ScreenGui.Destroying:Connect(function()
    for k, v in pairs(Config) do
        if type(v) == "boolean" then Config[k] = false end
    end
    saveConfig()

    clearTracked()
    safeDisconnect(mainHeartbeat)
    if noclipConn then safeDisconnect(noclipConn); noclipConn = nil end
    if DisasterGuiConn then safeDisconnect(DisasterGuiConn); DisasterGuiConn = nil end
    Threads = {}

    disableFly()
    disableFreeze()
    disableFullbright()
    disableNoFog()
    clearESP()
    destroySpinAuraParts()
    destroyOrbitParts()

    for _, p in ipairs(ChaosMeteors) do if p and p.Parent then p:Destroy() end end
    for _, p in ipairs(ChaosStormParts) do if p and p.Parent then p:Destroy() end end
    ChaosMeteors = {}
    ChaosStormParts = {}

    Workspace.Gravity = OriginalGravity
    local cam = Workspace.CurrentCamera
    if cam then cam.FieldOfView = OriginalFOV end

    if WaterPlatform then WaterPlatform:Destroy(); WaterPlatform = nil end
end)
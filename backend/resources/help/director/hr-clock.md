---
title: HR: clock-in location check and overtime warning
category: Staff Management
order: 67
roles: agency_admin, platform_admin, centre_director, educator, home_visitor
---
# HR: clock-in location check and overtime warning

Two optional clock-in rules, part of the KiddieTrac HR add-on. An agency admin sets them in **HR > Rota > Clock-in rules**.

## Location check at clock-in

Draw an area around each centre or home: a radius in metres around the address, or around a spot you click on the map. Then choose the rule:

- **Off**: nobody is asked for a location.
- **Allow, and flag**: clock-ins away from the centre, or without a location, still work. They are listed for directors and admins.
- **Block**: clock-ins away from the centre, or without a location, are refused with a plain reason. A director can still clock the person in.

The location is asked only when someone clocks in at a centre that has an area. Before the device shares it, the person sees why it is asked. KiddieTrac keeps only "at the centre" or "away" and the distance, never the location itself. Nobody is tracked, and clocking out is never blocked. The kiosk is not checked.

## Overtime warning

Set the weekly hours when overtime starts (44 by default, the Ontario Employment Standards threshold) and when to warn (40 by default). When someone's worked hours this week reach the warning, and again when they reach overtime, they and their centre's directors get a notice. Each one goes once a week. Contractors are left out unless you turn that off.

## What directors see

**HR > Rota** lists this week's clock-ins away from the centre, and the people close to or into overtime, for your own centres.

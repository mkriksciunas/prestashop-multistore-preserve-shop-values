<?php
/**
 * PrestaShop 1.7.8.11 ObjectModel override.
 *
 * In All shops context, preserve per-shop product prices, tax rules groups,
 * and wholesale prices when updating existing product_shop rows.
 */
class ObjectModel extends ObjectModelCore
{
    public function update($null_values = false)
    {
        // @hook actionObject<ObjectClassName>UpdateBefore
        Hook::exec('actionObjectUpdateBefore', ['object' => $this]);
        Hook::exec('actionObject' . $this->getFullyQualifiedName() . 'UpdateBefore', ['object' => $this]);

        $this->clearCache();

        // Automatically fill dates
        if (property_exists($this, 'date_upd')) {
            $this->date_upd = date('Y-m-d H:i:s');
            if (isset($this->update_fields) && is_array($this->update_fields) && count($this->update_fields)) {
                $this->update_fields['date_upd'] = true;
            }
        }

        // Automatically fill dates
        if (property_exists($this, 'date_add') && $this->date_add == null) {
            $this->date_add = date('Y-m-d H:i:s');
            if (isset($this->update_fields) && is_array($this->update_fields) && count($this->update_fields)) {
                $this->update_fields['date_add'] = true;
            }
        }

        $id_shop_list = Shop::getContextListShopID();
        if (count($this->id_shop_list)) {
            $id_shop_list = $this->id_shop_list;
        }

        if (Shop::checkIdShopDefault($this->def['table']) && !$this->id_shop_default) {
            $this->id_shop_default = (in_array(Configuration::get('PS_SHOP_DEFAULT'), $id_shop_list) == true) ? Configuration::get('PS_SHOP_DEFAULT') : min($id_shop_list);
        }

        // Database update for the base table. This remains unchanged.
        if (!$result = Db::getInstance()->update(
            $this->def['table'],
            $this->getFields(),
            '`' . pSQL($this->def['primary']) . '` = ' . (int) $this->id,
            0,
            $null_values
        )) {
            return false;
        }

        // Database insertion/update for multishop fields related to the object.
        if (Shop::isTableAssociated($this->def['table'])) {
            $fields = $this->getFieldsShop();

            // These values belong to each shop. Do not broadcast the loaded shop's
            // values to every product_shop row in All shops context.
            if ($this->def['table'] === 'product' && Shop::getContext() === Shop::CONTEXT_ALL) {
                unset(
                    $fields['price'],
                    $fields['id_tax_rules_group']
                );
            }

            $fields[$this->def['primary']] = (int) $this->id;
            if (is_array($this->update_fields)) {
                $update_fields = $this->update_fields;
                $this->update_fields = null;
                $all_fields = $this->getFieldsShop();
                $all_fields[$this->def['primary']] = (int) $this->id;
                $this->update_fields = $update_fields;
            } else {
                $all_fields = $fields;
            }

            foreach ($id_shop_list as $id_shop) {
                $fields['id_shop'] = (int) $id_shop;
                $all_fields['id_shop'] = (int) $id_shop;
                $where = $this->def['primary'] . ' = ' . (int) $this->id . ' AND id_shop = ' . (int) $id_shop;

                // Create a multishop entry only in a single-shop context.
                // In All shops context, update rows that already exist.
                $shop_exists = Db::getInstance()->getValue(
                    'SELECT ' . $this->def['primary'] . ' FROM ' . _DB_PREFIX_ . $this->def['table'] . '_shop WHERE ' . $where
                );
                if ($shop_exists) {
                    $result &= Db::getInstance()->update($this->def['table'] . '_shop', $fields, $where, 0, $null_values);
                } elseif (Shop::getContext() == Shop::CONTEXT_SHOP) {
                    $result &= Db::getInstance()->insert($this->def['table'] . '_shop', $all_fields, $null_values);
                }
            }
        }

        // Database update for multilingual fields related to the object.
        if (isset($this->def['multilang']) && $this->def['multilang']) {
            $fields = $this->getFieldsLang();
            if (is_array($fields)) {
                foreach ($fields as $field) {
                    foreach (array_keys($field) as $key) {
                        if (!Validate::isTableOrIdentifier($key)) {
                            throw new PrestaShopException('key ' . $key . ' is not a valid table or identifier');
                        }
                    }

                    // If this table is linked to multishop system, update / insert for all shops from context.
                    if ($this->isLangMultishop()) {
                        $id_shop_list = Shop::getContextListShopID();
                        if (count($this->id_shop_list)) {
                            $id_shop_list = $this->id_shop_list;
                        }

                        foreach ($id_shop_list as $id_shop) {
                            $field['id_shop'] = (int) $id_shop;
                            $where = pSQL($this->def['primary']) . ' = ' . (int) $this->id
                                . ' AND id_lang = ' . (int) $field['id_lang']
                                . ' AND id_shop = ' . (int) $id_shop;

                            if (Db::getInstance()->getValue(
                                'SELECT COUNT(*) FROM ' . pSQL(_DB_PREFIX_ . $this->def['table']) . '_lang WHERE ' . $where
                            )) {
                                $result &= Db::getInstance()->update($this->def['table'] . '_lang', $field, $where);
                            } else {
                                $result &= Db::getInstance()->insert($this->def['table'] . '_lang', $field);
                            }
                        }
                    } else {
                        // If this table is not linked to multishop system.
                        $where = pSQL($this->def['primary']) . ' = ' . (int) $this->id
                            . ' AND id_lang = ' . (int) $field['id_lang'];

                        if (Db::getInstance()->getValue(
                            'SELECT COUNT(*) FROM ' . pSQL(_DB_PREFIX_ . $this->def['table']) . '_lang WHERE ' . $where
                        )) {
                            $result &= Db::getInstance()->update($this->def['table'] . '_lang', $field, $where);
                        } else {
                            $result &= Db::getInstance()->insert($this->def['table'] . '_lang', $field, $null_values);
                        }
                    }
                }
            }
        }

        // @hook actionObject<ObjectClassName>UpdateAfter
        Hook::exec('actionObjectUpdateAfter', ['object' => $this]);
        Hook::exec('actionObject' . $this->getFullyQualifiedName() . 'UpdateAfter', ['object' => $this]);

        return $result;
    }
}
